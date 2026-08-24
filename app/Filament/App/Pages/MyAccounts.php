<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Employee's home page — a queue of accounts currently assigned to them,
 * as an organized, sortable table. Clicking a row opens the Activation
 * page. This is the core, highest-traffic screen for employees, so it
 * favors a familiar table over cards: scannable, sortable, and quick to
 * act on.
 */
class MyAccounts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'حساباتي';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.app.pages.my-accounts';

    public static function canAccess(): bool
    {
        return auth()->user()?->isEmployee() ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'حساباتي';
    }

    public function getLockedAssignment(): ?AccountAssignment
    {
        return AccountAssignment::lockedFor((int) auth()->id());
    }

    /**
     * "Today" snapshot for the employee looking at this page — count of
     * completed activations, their own average work time, the team's
     * average for the same day, a comparison percentage, and today's
     * earnings if the tenant has set a rate. All scoped to the current
     * user and today's calendar day only, so the numbers reset each
     * morning and reflect this shift's progress.
     *
     * @return array{
     *   completed_count:int, avg_minutes:?float, team_avg_minutes:?float,
     *   comparison_percent:?int, earnings:?string, currency_configured:bool
     * }
     */
    public function todayStats(): array
    {
        $u = auth()->user();
        $tenant = filament()->getTenant();
        $today = now()->toDateString();

        $completedToday = AccountAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('employee_id', $u->id)
            ->where('status', AccountAssignment::STATUS_COMPLETED)
            ->whereDate('completed_at', $today);

        $count = (clone $completedToday)->count();

        // Personal average — only rows that have BOTH timestamps (rows
        // completed before the timing columns existed are simply ignored,
        // never coerced to 0). Postgres FILTER is exact.
        $mine = (float) DB::table('account_assignments')
            ->where('tenant_id', $tenant->id)
            ->where('employee_id', $u->id)
            ->where('status', AccountAssignment::STATUS_COMPLETED)
            ->whereDate('completed_at', $today)
            ->whereNotNull('credentials_revealed_at')
            ->whereNotNull('submitted_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (submitted_at - credentials_revealed_at)) / 60) as m')
            ->value('m');

        // Team average — every employee in the tenant, same day. Owner
        // hasn't been given a "team" concept beyond the tenant, so tenant
        // is the right comparison group.
        $teamEmployeeIds = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value))
            ->pluck('id');

        $team = (float) DB::table('account_assignments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('employee_id', $teamEmployeeIds)
            ->where('status', AccountAssignment::STATUS_COMPLETED)
            ->whereDate('completed_at', $today)
            ->whereNotNull('credentials_revealed_at')
            ->whereNotNull('submitted_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (submitted_at - credentials_revealed_at)) / 60) as m')
            ->value('m');

        // Comparison % — positive = employee is faster than team, negative = slower.
        // Rounded to a whole number since fractional percents read as noise.
        $comparison = null;
        if ($mine > 0 && $team > 0) {
            $comparison = (int) round((($team - $mine) / $team) * 100);
        }

        $rate = $tenant->commission_per_activation;
        $earnings = $rate !== null ? number_format($count * (float) $rate, 2) : null;

        return [
            'completed_count'     => $count,
            'avg_minutes'         => $mine > 0 ? round($mine, 1) : null,
            'team_avg_minutes'    => $team > 0 ? round($team, 1) : null,
            'comparison_percent'  => $comparison,
            'earnings'            => $earnings,
            'currency_configured' => $rate !== null,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AccountAssignment::query()
                    ->with('account')
                    ->where('employee_id', auth()->id())
                    ->where('tenant_id', filament()->getTenant()?->id)
                    ->whereIn('status', [
                        AccountAssignment::STATUS_PENDING,
                        AccountAssignment::STATUS_IN_PROGRESS,
                        AccountAssignment::STATUS_AWAITING_REVIEW,
                    ])
            )
            ->columns([
                Tables\Columns\TextColumn::make('account.id')
                    ->label('حساب رقم')
                    ->prefix('#')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (AccountAssignment $record): string => match (true) {
                        $record->status === AccountAssignment::STATUS_IN_PROGRESS && $record->rejection_reason => 'danger',
                        $record->status === AccountAssignment::STATUS_AWAITING_REVIEW => 'primary',
                        $record->status === AccountAssignment::STATUS_IN_PROGRESS => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (AccountAssignment $record): string => match (true) {
                        $record->status === AccountAssignment::STATUS_IN_PROGRESS && $record->rejection_reason => 'مُعاد',
                        $record->status === AccountAssignment::STATUS_AWAITING_REVIEW => 'بانتظار المراجعة',
                        $record->status === AccountAssignment::STATUS_IN_PROGRESS => 'قيد التنفيذ',
                        default => 'بانتظارك',
                    })
                    ->icon(fn (AccountAssignment $record): ?string => $record->status === AccountAssignment::STATUS_IN_PROGRESS && $record->rejection_reason
                        ? 'heroicon-m-arrow-uturn-left'
                        : null),

                Tables\Columns\TextColumn::make('rejection_reason')
                    ->label('سبب الرفض')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (AccountAssignment $record): ?string => $record->rejection_reason)
                    ->color('danger')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('ملاحظة من المشرف')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (AccountAssignment $record): ?string => $record->notes)
                    ->icon('heroicon-m-chat-bubble-left-ellipsis')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('assigned_at')
                    ->label('مُنذ')
                    ->since()
                    ->sortable()
                    ->badge()
                    ->color(fn (AccountAssignment $record): string => ($record->assigned_at?->diffInHours(now()) ?? 0) >= 24 ? 'danger' : 'gray')
                    ->icon(fn (AccountAssignment $record): ?string => ($record->assigned_at?->diffInHours(now()) ?? 0) >= 24 ? 'heroicon-m-clock' : null),
            ])
            ->recordUrl(function (AccountAssignment $record) {
                $locked = $this->getLockedAssignment();
                if ($locked !== null && $locked->id !== $record->id) {
                    return null;
                }
                return route('filament.app.pages.activation', [
                    'tenant'     => filament()->getTenant()->slug,
                    'assignment' => $record->id,
                ]);
            })
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(function (AccountAssignment $record): string {
                        $locked = $this->getLockedAssignment();
                        return ($locked !== null && $locked->id !== $record->id) ? 'مقفل — أكمل الحساب الحالي أولاً' : 'افتح';
                    })
                    ->icon('heroicon-m-arrow-left')
                    ->iconPosition('after')
                    ->disabled(function (AccountAssignment $record): bool {
                        $locked = $this->getLockedAssignment();
                        return $locked !== null && $locked->id !== $record->id;
                    })
                    ->url(fn (AccountAssignment $record) => route('filament.app.pages.activation', [
                        'tenant'     => filament()->getTenant()->slug,
                        'assignment' => $record->id,
                    ])),
            ])
            ->filters([
                Tables\Filters\Filter::make('urgent')
                    ->label('عاجل (+24 ساعة)')
                    ->query(fn (Builder $query) => $query->where('assigned_at', '<=', now()->subHours(24))),
            ])
            ->defaultSort('assigned_at')
            ->paginated([10, 25, 50])
            // Card-grid layout instead of horizontal-scroll table — this
            // page is employee-only (canAccess restricts it) and every
            // employee is on a phone, so a table with columns off-screen
            // to the right is exactly the wrong shape. contentGrid stacks
            // the columns vertically inside each row (like a card) and
            // packs cards two-across from `md` up in case anyone tablets.
            ->contentGrid(['default' => 1, 'md' => 2])
            ->emptyStateHeading('لا يوجد حسابات مخصَّصة حالياً')
            ->emptyStateDescription('تواصل مع مشرفك عند تسلّم دفعة جديدة.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
