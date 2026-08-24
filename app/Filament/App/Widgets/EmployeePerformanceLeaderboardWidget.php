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
 * Top performers by completed activations, scoped to the same reach as
 * OverviewStatsWidget (owner sees everyone, manager/supervisor see only
 * their own office reach). Lets a manager spot who's fast and reliable
 * vs. who needs a nudge without digging through the raw assignment list
 * by hand.
 */
class EmployeePerformanceLeaderboardWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('الأعلى أداءً')
            ->query($this->leaderboardQuery())
            ->columns([
                TextColumn::make('employee.name')
                    ->label('الموظف')
                    ->weight('bold'),

                TextColumn::make('completed_count')
                    ->label('مكتملة')
                    ->badge()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('failed_count')
                    ->label('فاشلة')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('success_rate')
                    ->label('نسبة النجاح')
                    ->state(function (AccountAssignment $record): string {
                        $total = $record->completed_count + $record->failed_count;
                        if ($total === 0) {
                            return '—';
                        }

                        return number_format(($record->completed_count / $total) * 100, 0).'%';
                    }),

                TextColumn::make('avg_minutes')
                    ->label('متوسط وقت الإكمال')
                    ->state(fn (AccountAssignment $record): string => $record->avg_minutes !== null
                        ? number_format((float) $record->avg_minutes, 0).' د'
                        : '—'),
            ])
            ->defaultSort('completed_count', 'desc')
            ->emptyStateHeading('لا توجد تفعيلات مكتملة أو فاشلة بعد');
    }

    private function leaderboardQuery(): Builder
    {
        $tenantId = filament()->getTenant()?->id;
        $u = auth()->user();

        $query = AccountAssignment::query()
            ->with('employee')
            ->selectRaw('employee_id')
            ->selectRaw('MIN(id) as id')
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'completed') as completed_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'failed') as failed_count")
            // Employee's actual work time = credentials revealed → proof
            // submitted. Using completed_at (= supervisor approved) would
            // conflate "employee was slow" with "supervisor was slow to
            // review", making the metric useless for evaluating employees.
            // Older rows that predate the credentials_revealed_at column
            // fall back to started_at so their history isn't lost.
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (submitted_at - COALESCE(credentials_revealed_at, started_at))) / 60) "
                ."FILTER (WHERE status = 'completed' AND submitted_at IS NOT NULL AND (credentials_revealed_at IS NOT NULL OR started_at IS NOT NULL)) "
                .'as avg_minutes')
            ->whereIn('status', [AccountAssignment::STATUS_COMPLETED, AccountAssignment::STATUS_FAILED]);

        if ($tenantId === null || $u === null) {
            return $query->whereRaw('1 = 0')->groupBy('employee_id');
        }

        $query->where('tenant_id', $tenantId);

        $inScopeEmployeeIds = $this->inScopeEmployeeIds($u);
        if ($inScopeEmployeeIds !== null) {
            $query->whereIn('employee_id', $inScopeEmployeeIds);
        }

        return $query->groupBy('employee_id')->limit(10);
    }

    /**
     * Same reach rule as OverviewStatsWidget: owner sees every employee
     * (null = no filter), manager/supervisor only their own office reach.
     *
     * @return array<int,int>|null
     */
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
