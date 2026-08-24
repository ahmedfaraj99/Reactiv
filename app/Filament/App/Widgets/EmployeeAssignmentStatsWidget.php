<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Models\AccountAssignment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Personal snapshot of the employee's own assignments — split out from
 * the "حساباتي" list page so the dashboard carries the numbers and the
 * accounts page stays a pure working list.
 */
class EmployeeAssignmentStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isEmployee() ?? false;
    }

    protected function getStats(): array
    {
        $assignments = AccountAssignment::query()
            ->where('employee_id', auth()->id())
            ->whereIn('status', [
                AccountAssignment::STATUS_PENDING,
                AccountAssignment::STATUS_IN_PROGRESS,
                AccountAssignment::STATUS_AWAITING_REVIEW,
            ])
            ->get(['status', 'assigned_at']);

        $total          = $assignments->count();
        $inProgress     = $assignments->where('status', AccountAssignment::STATUS_IN_PROGRESS)->count();
        $awaitingReview = $assignments->where('status', AccountAssignment::STATUS_AWAITING_REVIEW)->count();
        $urgent         = $assignments->filter(fn ($a) => $a->assigned_at?->diffInHours(now()) >= 24)->count();

        return [
            Stat::make('إجمالي المخصَّص لك', number_format($total))
                ->descriptionIcon('heroicon-m-inbox-stack')
                ->color('gray'),

            Stat::make('قيد التنفيذ', number_format($inProgress))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning'),

            Stat::make('بانتظار المراجعة', number_format($awaitingReview))
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('primary'),

            Stat::make('عاجل (+24 ساعة)', number_format($urgent))
                ->descriptionIcon('heroicon-m-clock')
                ->color($urgent > 0 ? 'danger' : 'gray'),
        ];
    }
}
