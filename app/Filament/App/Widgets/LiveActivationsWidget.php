<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Enums\UserRole;
use App\Models\AccountAssignment;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Live "who's working on what right now" board for the supervisor.
 * Every in-progress assignment shows up here with its employee, account,
 * time elapsed since start, and the last step reached — polling every
 * 15 seconds so a stuck employee (e.g. 15 min on the TOTP step) becomes
 * visible in near-real-time and the supervisor can reach out BEFORE the
 * assignment fails, instead of finding out via a rejection later.
 *
 * Scoped identically to OverviewStatsWidget / EmployeePerformanceLeaderboard:
 * owner sees every in-flight in the tenant, manager sees their managed
 * offices only, supervisor sees their single office only.
 */
class LiveActivationsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('التفعيلات الجارية الآن')
            ->query($this->liveQuery())
            ->columns([
                TextColumn::make('employee.name')
                    ->label('الموظف')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('account_id')
                    ->label('الحساب')
                    ->prefix('#')
                    ->fontFamily('mono'),

                TextColumn::make('current_step')
                    ->label('الخطوة الحالية')
                    ->badge()
                    ->state(fn (AccountAssignment $r): string => match (true) {
                        $r->submitted_at !== null       => 'إثبات مرفوع',
                        $r->first_totp_at !== null      => 'كود TOTP',
                        $r->credentials_revealed_at !== null => 'كشف البيانات',
                        default                          => 'لم يفتح بعد',
                    })
                    ->color(fn (AccountAssignment $r): string => match (true) {
                        $r->submitted_at !== null       => 'primary',
                        $r->first_totp_at !== null      => 'warning',
                        $r->credentials_revealed_at !== null => 'info',
                        default                          => 'gray',
                    }),

                TextColumn::make('elapsed')
                    ->label('منذ')
                    ->state(function (AccountAssignment $r): string {
                        $ref = $r->credentials_revealed_at ?? $r->started_at ?? $r->assigned_at;
                        if ($ref === null) {
                            return '—';
                        }
                        $mins = (int) $ref->diffInMinutes(now());
                        return $mins.' د';
                    })
                    ->badge()
                    // Highlight stuck assignments: 15+ min without moving to
                    // the next step is the supervisor's cue to reach out.
                    ->color(function (AccountAssignment $r): string {
                        $ref = $r->credentials_revealed_at ?? $r->started_at ?? $r->assigned_at;
                        if ($ref === null) {
                            return 'gray';
                        }
                        $mins = (int) $ref->diffInMinutes(now());
                        return match (true) {
                            $mins >= 30 => 'danger',
                            $mins >= 15 => 'warning',
                            default     => 'success',
                        };
                    }),

                TextColumn::make('assigned_at')
                    ->label('تخصيص')
                    ->since()
                    ->toggleable(),
            ])
            ->defaultSort('credentials_revealed_at', 'desc')
            ->emptyStateHeading('لا يوجد تفعيلات جارية الآن')
            ->emptyStateDescription('لما يبدأ موظف تفعيلاً، يظهر هنا لحظياً.');
    }

    private function liveQuery(): Builder
    {
        $tenantId = filament()->getTenant()?->id;
        $u = auth()->user();

        $query = AccountAssignment::query()
            ->with('employee')
            ->where('status', AccountAssignment::STATUS_IN_PROGRESS);

        if ($tenantId === null || $u === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('tenant_id', $tenantId);

        $inScopeEmployeeIds = $this->inScopeEmployeeIds($u);
        if ($inScopeEmployeeIds !== null) {
            $query->whereIn('employee_id', $inScopeEmployeeIds);
        }

        return $query;
    }

    /** @return array<int,int>|null */
    private function inScopeEmployeeIds(User $u): ?array
    {
        if ($u->isTenantOwner()) {
            return null;
        }

        $q = User::query()->whereHas('roles', fn ($r) => $r->where('name', UserRole::Employee->value));

        if ($u->isManager()) {
            return $q->whereIn('office_id', $u->managedOffices()->pluck('id'))->pluck('id')->all();
        }

        if ($u->isSupervisor()) {
            return $q->where('office_id', $u->office_id)->pluck('id')->all();
        }

        return [];
    }
}
