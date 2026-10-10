<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClubCreation;

use App\Enums\UserRole;
use App\Filament\App\Resources\ClubCreation\TotpRequestResource;
use App\Http\Controllers\Controller;
use App\Models\ClubCreation\Account;
use App\Models\ClubCreation\Batch;
use App\Models\User;
use App\Services\TotpService;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    public function show(Request $request, string $token)
    {
        $batch = Batch::where('token', $token)->firstOrFail();

        if ($batch->isRevoked() || $batch->isExpired()) {
            abort(404);
        }

        if ($batch->opened_at === null) {
            $batch->update([
                'opened_at'     => now(),
                'first_open_ip' => $request->ip(),
                // Truncate to fit the column and keep the log readable
                // even for the long UA strings some devices send.
                'first_open_ua' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        }

        $accounts = $batch->accounts()->orderBy('id')->get();

        return response()
            ->view('club-creation.deliver', [
                'batch'    => $batch,
                'accounts' => $accounts,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function markDone(Request $request, string $token, int $accountId): JsonResponse
    {
        $account = $this->liveAccount($token, $accountId);

        if ($account->status === Account::STATUS_ASSIGNED) {
            $account->update([
                'status'  => Account::STATUS_DONE,
                'done_at' => now(),
            ]);
        }

        return response()->json([
            'status'  => $account->status,
            'done_at' => $account->done_at?->format('Y-m-d H:i'),
        ]);
    }

    /**
     * Same gate as the activation page's console code: one code per
     * account (TOTP_BASE_LIMIT) plus whatever the owner approved. The
     * check-then-increment runs under a row lock so two taps (or two
     * open tabs) can't both spend the last allowance.
     */
    public function totp(Request $request, string $token, int $accountId, TotpService $totp): JsonResponse
    {
        $account = $this->liveAccount($token, $accountId);

        if ($blocked = $this->blockedReason($account)) {
            return $blocked;
        }

        $granted = DB::transaction(function () use ($account): bool {
            $locked = Account::query()->lockForUpdate()->find($account->id);
            if ($locked === null || ! $locked->canGenerateTotp()) {
                return false;
            }

            $locked->update([
                'totp_generations' => $locked->totp_generations + 1,
                'first_totp_at'    => $locked->first_totp_at ?? now(),
            ]);

            return true;
        });
        $account->refresh();

        if (! $granted) {
            return response()->json(['error' => 'limit'] + $this->totpState($account), 429);
        }

        $code = $totp->currentCode((string) $account->totp_seed)['code'];

        return response()->json([
            'code'    => $code,
            'seconds' => (int) config('fc27ac.totp_display_seconds'),
        ] + $this->totpState($account));
    }

    /**
     * The worker hit the cap — flag the account for the tenant owner and
     * ping them in the panel bell. Idempotent: a second tap while a
     * request is pending neither re-stamps the time nor re-notifies.
     */
    public function requestTotp(Request $request, string $token, int $accountId): JsonResponse
    {
        $account = $this->liveAccount($token, $accountId);

        if ($blocked = $this->blockedReason($account)) {
            return $blocked;
        }

        if ($account->canGenerateTotp()) {
            return response()->json($this->totpState($account));
        }

        $flagged = Account::query()
            ->whereKey($account->id)
            ->whereNull('totp_requested_at')
            ->update(['totp_requested_at' => now()]);
        $account->refresh();

        if ($flagged > 0) {
            $this->notifyOwners($account);
        }

        return response()->json($this->totpState($account));
    }

    /**
     * Polled by the public page while a request is pending so the button
     * flips back to "generate" as soon as the owner approves.
     */
    public function totpStatus(Request $request, string $token, int $accountId): JsonResponse
    {
        return response()->json($this->totpState($this->liveAccount($token, $accountId)));
    }

    private function liveAccount(string $token, int $accountId): Account
    {
        $batch = Batch::where('token', $token)->firstOrFail();

        if ($batch->isRevoked() || $batch->isExpired()) {
            abort(404);
        }

        return Account::where('id', $accountId)
            ->where('batch_id', $batch->id)
            ->firstOrFail();
    }

    /**
     * Emergency freeze stops code generation here too, same as the
     * activation page — the public link is the riskier surface.
     */
    private function blockedReason(Account $account): ?JsonResponse
    {
        if ($account->tenant?->isFrozen()) {
            return response()->json(['error' => 'frozen', 'message' => 'النظام متوقف مؤقتاً. حاول لاحقاً.'], 423);
        }

        if ($account->status !== Account::STATUS_ASSIGNED) {
            return response()->json(['error' => 'done', 'message' => 'هذا الحساب مكتمل.'], 409);
        }

        if (! $account->hasTotp()) {
            return response()->json(['error' => 'no_seed', 'message' => 'لا يوجد كود TOTP لهذا الحساب — تواصل معنا.'], 409);
        }

        return null;
    }

    /**
     * @return array{used:int, allowance:int, can_generate:bool, pending:bool}
     */
    private function totpState(Account $account): array
    {
        return [
            'used'         => $account->totp_generations,
            'allowance'    => $account->totpAllowance(),
            'can_generate' => $account->canGenerateTotp(),
            'pending'      => $account->hasPendingTotpRequest(),
        ];
    }

    private function notifyOwners(Account $account): void
    {
        $owners = User::query()
            ->where('tenant_id', $account->tenant_id)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::TenantOwner->value))
            ->get();

        if ($owners->isEmpty()) {
            return;
        }

        $account->loadMissing('batch', 'tenant');

        $notification = Notification::make()
            ->warning()
            ->title('طلب كود TOTP إضافي — إنشاء الحسابات')
            ->body('حساب '.$account->email.' — المستلم: '.($account->batch?->recipient ?? '—'));

        if ($account->tenant !== null) {
            $notification->actions([
                NotificationAction::make('review')
                    ->label('مراجعة الطلب')
                    ->url(TotpRequestResource::getUrl('index', panel: 'app', tenant: $account->tenant)),
            ]);
        }

        $notification->sendToDatabase($owners);
    }
}
