<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Alert;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * At-a-glance operational snapshot shown at the top of the dashboard.
 * Only owner/manager/supervisor see it (employees are redirected away
 * from the dashboard entirely). Numbers are scoped to each role's reach.
 */
class OverviewStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

    protected function getStats(): array
    {
        $tenantId = filament()->getTenant()?->id;
        $u = auth()->user();
        if ($tenantId === null || $u === null) {
            return [];
        }

        $inScopeEmployeeIds = $this->inScopeEmployeeIds($u);
        $accounts = $this->scopedAccountsQuery($u, $tenantId);

        $available = (clone $accounts)->where('accounts.status', 'available')->count();
        $assigned  = (clone $accounts)->where('accounts.status', 'assigned')->count();
        $activated = (clone $accounts)->where('accounts.status', 'activated')->count();

        $awaitingReview = AccountAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('status', AccountAssignment::STATUS_AWAITING_REVIEW)
            ->when($inScopeEmployeeIds !== null, fn ($q) => $q->whereIn('employee_id', $inScopeEmployeeIds))
            ->count();

        $unresolvedAlerts = Alert::query()
            ->where('tenant_id', $tenantId)
            ->where('resolved', false)
            ->when($inScopeEmployeeIds !== null, fn ($q) => $q->whereIn('user_id', $inScopeEmployeeIds))
            ->count();

        $employees = User::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value))
            ->when($inScopeEmployeeIds !== null, fn ($q) => $q->whereIn('id', $inScopeEmployeeIds))
            ->count();

        return [
            Stat::make('حسابات متاحة', number_format($available))
                ->description('في المخزون بانتظار التوزيع')
                ->descriptionIcon('heroicon-m-inbox')
                ->color('success'),

            Stat::make('قيد التفعيل', number_format($assigned))
                ->description('مخصَّصة لموظفين حالياً')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning'),

            Stat::make('مفعَّلة', number_format($activated))
                ->description('اكتملت بنجاح')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('primary'),

            Stat::make('بانتظار المراجعة', number_format($awaitingReview))
                ->description($awaitingReview > 0 ? 'إثباتات تحتاج اعتماداً' : 'لا يوجد جديد')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color($awaitingReview > 0 ? 'warning' : 'gray'),

            Stat::make('تنبيهات غير محلولة', number_format($unresolvedAlerts))
                ->description($unresolvedAlerts > 0 ? 'راجع صفحة التنبيهات' : 'كل شيء تحت السيطرة')
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($unresolvedAlerts > 0 ? 'danger' : 'gray'),

            Stat::make('الموظفون النشطون', number_format($employees))
                ->description($this->employeesScopeLabel($u))
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
        ];
    }

    /**
     * Owner sees every employee (returns null → no filter). Manager/Supervisor
     * see only employees in their reach; returns their IDs, or [] if none.
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

    /**
     * Accounts query scoped to the current user's reach. Each account
     * belongs to exactly one manager's batch (accounts.manager_id, set
     * at upload time — see AccountResource's import action) regardless
     * of whether it's been distributed yet, so that's the scope: a
     * manager's own batch, or a supervisor's manager's batch. Not
     * scoped by assignment/employee — an unassigned account still
     * belongs to the manager who owns the batch, not to "anyone".
     */
    private function scopedAccountsQuery(User $u, int $tenantId): Builder
    {
        $q = Account::query()->where('accounts.tenant_id', $tenantId);

        if ($u->isTenantOwner()) {
            return $q;
        }

        if ($u->isManager()) {
            return $q->where('accounts.manager_id', $u->id);
        }

        if ($u->isSupervisor()) {
            return $q->where('accounts.manager_id', $u->office?->manager_id);
        }

        return $q->whereRaw('1 = 0');
    }

    private function employeesScopeLabel(User $u): string
    {
        if ($u->isTenantOwner()) {
            return 'عبر كل المكاتب';
        }
        if ($u->isManager()) {
            return 'في المكاتب التي تديرها';
        }
        return 'في مكتبك';
    }
}
