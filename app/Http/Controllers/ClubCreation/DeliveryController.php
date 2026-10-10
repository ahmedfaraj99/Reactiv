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
     * Same gate as the activation page: one code per account per kind
     * (console / EA) plus whatever the owner approved. The check-then-
     * increment runs under a row lock so two taps (or two open tabs)
     * can't both spend the last allowance.
     */
    public function totp(Request $request, string $token, int $accountId, string $kind, TotpService $totp): JsonResponse
    {
        $account = $this->liveAccount($token, $accountId);

        if ($blocked = $this->blockedReason($account, $kind)) {
            return $blocked;
        }

        $granted = DB::transaction(function () use ($account, $kind): bool {
            $locked = Account::query()->lockForUpdate()->find($account->id);
            if ($locked === null || ! $locked->canGenerateTotp($kind)) {
                return false;
            }

            $locked->update([
                Account::totpColumn($kind, 'totp_generations') => $locked->totpUsed($kind) + 1,
                'first_totp_at' => $locked->first_totp_at ?? now(),
            ]);

            return true;
        });
        $account->refresh();

        if (! $granted) {
            return response()->json(['error' => 'limit'] + $this->totpState($account, $kind), 429);
        }

        $code = $totp->currentCode((string) $account->totpSeedFor($kind))['code'];

        return response()->json([
            'code'    => $code,
            'seconds' => (int) config('fc27ac.totp_display_seconds'),
        ] + $this->totpState($account, $kind));
    }

    /**
     * The worker hit the cap — flag the account for the tenant owner and
     * ping them in the panel bell. Idempotent: a second tap while a
     * request is pending neither re-stamps the time nor re-notifies.
     */
    public function requestTotp(Request $request, string $token, int $accountId, string $kind): JsonResponse
    {
        $account = $this->liveAccount($token, $accountId);

        if ($blocked = $this->blockedReason($account, $kind)) {
            return $blocked;
        }

        if ($account->canGenerateTotp($kind)) {
            return response()->json($this->totpState($account, $kind));
        }

        $column = Account::totpColumn($kind, 'totp_requested_at');
        $flagged = Account::query()
            ->whereKey($account->id)
            ->whereNull($column)
            ->update([$column => now()]);
        $account->refresh();

        if ($flagged > 0) {
            $this->notifyOwners($account, $kind);
        }

        return response()->json($this->totpState($account, $kind));
    }

    /**
     * Polled by the public page while a request is pending so the button
     * flips back to "generate" as soon as the owner approves.
     */
    public function totpStatus(Request $request, string $token, int $accountId, string $kind): JsonResponse
    {
        return response()->json($this->totpState($this->liveAccount($token, $accountId), $kind));
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
    private function blockedReason(Account $account, string $kind): ?JsonResponse
    {
        if ($account->tenant?->isFrozen()) {
            return response()->json(['error' => 'frozen', 'message' => 'النظام متوقف مؤقتاً. حاول لاحقاً.'], 423);
        }

        if ($account->status !== Account::STATUS_ASSIGNED) {
            return response()->json(['error' => 'done', 'message' => 'هذا الحساب مكتمل.'], 409);
        }

        if (! $account->hasTotp($kind)) {
            return response()->json(['error' => 'no_seed', 'message' => 'لا يوجد كود TOTP لهذا الحساب — تواصل معنا.'], 409);
        }

        return null;
    }

    /**
     * @return array{used:int, allowance:int, can_generate:bool, pending:bool}
     */
    private function totpState(Account $account, string $kind): array
    {
        return [
            'used'         => $account->totpUsed($kind),
            'allowance'    => $account->totpAllowance($kind),
            'can_generate' => $account->canGenerateTotp($kind),
            'pending'      => $account->hasPendingTotpRequest($kind),
        ];
    }

    private function notifyOwners(Account $account, string $kind): void
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
            ->title('طلب كود '.($kind === Account::KIND_EA ? 'EA' : $account->platformLabel()).' إضافي — إنشاء الحسابات')
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
