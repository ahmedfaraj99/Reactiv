<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Client;
use App\Models\WipeLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The owner's daily security hygiene: hand each client the list of
 * accounts activated today, then wipe the actual credentials from the DB
 * so a future break-in yields yesterday's history but no live secrets.
 *
 * Only the six credential fields (psn_password, psn_totp_seed,
 * ea_password, ea_totp_seed, ea_backup_code_1, ea_backup_code_2) are
 * nulled — email, timestamps, employee_id, proof screenshots, and every
 * reveal_log/alert stays intact so a customer complaint days later can
 * still be traced back to the exact employee.
 */
class EndOfDayWipe extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'نهاية اليوم';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.app.pages.end-of-day-wipe';

    /** The six columns the wipe nulls out. Referenced by tests too. */
    public const CREDENTIAL_COLUMNS = [
        'psn_password',
        'psn_totp_seed',
        'ea_password',
        'ea_totp_seed',
        'ea_backup_code_1',
        'ea_backup_code_2',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->isTenantOwner() ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'نهاية اليوم — تسليم و مسح';
    }

    /**
     * Live per-client counts of accounts eligible to be wiped: the account
     * is currently assigned, that assignment is completed, and the
     * credentials haven't already been nulled by a prior wipe.
     *
     * @return array<int,array{client:Client,count:int}>
     */
    public function rows(): array
    {
        $tenantId = filament()->getTenant()?->id;
        if ($tenantId === null) {
            return [];
        }

        $counts = Account::query()
            ->join('account_assignments', 'account_assignments.account_id', '=', 'accounts.id')
            ->where('accounts.tenant_id', $tenantId)
            ->whereNotNull('accounts.client_id')
            ->where('account_assignments.status', AccountAssignment::STATUS_COMPLETED)
            // Already-wiped accounts have all six credential columns null.
            // Filtering on psn_password is enough — the wipe always nulls all six together.
            ->whereNotNull('accounts.psn_password')
            ->select('accounts.client_id', DB::raw('COUNT(*) AS cnt'))
            ->groupBy('accounts.client_id')
            ->pluck('cnt', 'client_id');

        return Client::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $counts->keys())
            ->orderBy('name')
            ->get()
            ->map(fn (Client $c) => [
                'client' => $c,
                'count'  => (int) ($counts[$c->id] ?? 0),
            ])
            ->all();
    }

    public function exportAction(): Action
    {
        return Action::make('export')
            ->label('تصدير CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->requiresConfirmation(false)
            ->action(function (array $arguments): StreamedResponse {
                return $this->streamCsv((int) $arguments['client']);
            });
    }

    public function wipeAction(): Action
    {
        return Action::make('wipe')
            ->label('تصدير و مسح')
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('مسح كامل غير قابل للتراجع')
            ->modalDescription('سيتم تحميل CSV بأسماء الحسابات الآن، وبعدها يتم مسح الرموز نهائياً من قاعدة البيانات. الملف يظهر لك مرة واحدة فقط.')
            ->modalSubmitActionLabel('نعم، حمّل و امسح')
            ->action(function (array $arguments) {
                return $this->wipeClient((int) $arguments['client']);
            });
    }

    private function streamCsv(int $clientId): StreamedResponse
    {
        // Defense-in-depth: canAccess() gates mount, but a replayed
        // Livewire payload from a non-owner with panel access shouldn't
        // ever reach here without paying the toll again.
        abort_unless(auth()->user()?->isTenantOwner(), 403);

        $tenantId = filament()->getTenant()?->id;
        $client = Client::query()->where('tenant_id', $tenantId)->findOrFail($clientId);

        $accounts = $this->eligibleAccountsQuery($tenantId, $clientId)->get();

        // Arabic-only names slugify to empty — fall back to the numeric id
        // so the filename never has a bare double-dash.
        $slug = (string) str($client->name)->slug();
        if ($slug === '') {
            $slug = 'client-'.$client->id;
        }
        $filename = sprintf('activated-%s-%s.csv', $slug, now()->format('Y-m-d-His'));

        return response()->streamDownload(function () use ($accounts): void {
            $handle = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens Arabic correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['email', 'ea_email', 'activated_at', 'employee']);
            foreach ($accounts as $account) {
                fputcsv($handle, [
                    $account->email,
                    $account->ea_email,
                    optional($account->assignment?->completed_at)->toDateTimeString(),
                    $account->assignment?->employee?->name,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function wipeClient(int $clientId)
    {
        abort_unless(auth()->user()?->isTenantOwner(), 403);

        $tenantId = filament()->getTenant()?->id;
        $user = auth()->user();
        $client = Client::query()->where('tenant_id', $tenantId)->findOrFail($clientId);

        // Snapshot ids BEFORE the CSV stream and the update — the update
        // then targets exactly the same set the CSV was built from, no
        // matter what a concurrent employee completes mid-wipe.
        $ids = $this->eligibleAccountsQuery($tenantId, $clientId)->pluck('accounts.id')->all();

        if ($ids === []) {
            Notification::make()->warning()->title('لا شيء للمسح')->body('لا توجد حسابات مؤهلة حالياً.')->send();
            return null;
        }

        $csvResponse = $this->streamCsv($clientId);

        // Re-check whereNotNull inside the update so a racing wipe from
        // another session doesn't get double-counted — the update returns
        // exactly the rows THIS call actually cleared. Skip the WipeLog
        // entirely if the other wiper beat us.
        $affected = DB::transaction(function () use ($ids, $tenantId, $user, $client): int {
            $nulls = array_fill_keys(self::CREDENTIAL_COLUMNS, null);
            $count = Account::query()
                ->whereIn('id', $ids)
                ->whereNotNull('psn_password')
                ->update($nulls);

            if ($count > 0) {
                WipeLog::create([
                    'tenant_id'      => $tenantId,
                    'client_id'      => $client->id,
                    'wiped_by'       => $user->id,
                    'accounts_wiped' => $count,
                    'ip'             => request()->ip(),
                    'wiped_at'       => now(),
                ]);
            }

            return $count;
        });

        if ($affected === 0) {
            Notification::make()->warning()->title('تم المسح بالفعل')->body('نُفّذ المسح من جلسة أخرى قبل ثوانٍ.')->send();
            return $csvResponse;
        }

        Notification::make()
            ->success()
            ->title('تم المسح')
            ->body(sprintf('تم مسح رموز %d حساب للعميل %s.', $affected, $client->name))
            ->send();

        return $csvResponse;
    }

    private function eligibleAccountsQuery(int $tenantId, int $clientId)
    {
        return Account::query()
            ->with(['assignment.employee'])
            ->join('account_assignments', 'account_assignments.account_id', '=', 'accounts.id')
            ->where('accounts.tenant_id', $tenantId)
            ->where('accounts.client_id', $clientId)
            ->where('account_assignments.status', AccountAssignment::STATUS_COMPLETED)
            ->whereNotNull('accounts.psn_password')
            ->select('accounts.*')
            ->orderBy('account_assignments.completed_at');
    }
}
