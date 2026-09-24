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
 *
 * Two rows of four:
 *   1. Current state (open, in progress, awaiting review, urgent)
 *   2. Completion history (today, this week, this month, all time)
 */
class EmployeeAssignmentStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    public static function canView(): bool
    {
        return auth()->user()?->isEmployee() ?? false;
    }

    protected function getStats(): array
    {
        $userId = (int) auth()->id();
        $now    = now();

        // Open work (single query with grouped counts is overkill for
        // 3 statuses — the plain get() below stays readable and only
        // returns the ~5-15 rows an employee has open at once).
        $open = AccountAssignment::query()
            ->where('employee_id', $userId)
            ->whereIn('status', [
                AccountAssignment::STATUS_PENDING,
                AccountAssignment::STATUS_IN_PROGRESS,
                AccountAssignment::STATUS_AWAITING_REVIEW,
            ])
            ->get(['status', 'assigned_at']);

        $openTotal      = $open->count();
        $inProgress     = $open->where('status', AccountAssignment::STATUS_IN_PROGRESS)->count();
        $awaitingReview = $open->where('status', AccountAssignment::STATUS_AWAITING_REVIEW)->count();
        $urgent         = $open->filter(fn ($a) => $a->assigned_at?->diffInHours($now) >= 24)->count();

        // Completion history uses `completed_at`, which is stamped when
        // the supervisor finalizes the activation. `submitted_at` would
        // count proof uploads that were later rejected — misleading for
        // an "how many did I actually finish" number.
        $completedBase = AccountAssignment::query()
            ->where('employee_id', $userId)
            ->where('status', AccountAssignment::STATUS_COMPLETED);

        // "Today" is credited by submission day, same rule the supervisors'
        // DailyPerformance page judges the daily target by — so the number
        // the employee sees here is the one they're measured on.
        $completedToday = AccountAssignment::query()
            ->where('employee_id', $userId)
            ->doneBetween($now->copy()->startOfDay(), $now->copy()->startOfDay()->addDay())
            ->count();
        $target = auth()->user()->effectiveDailyTarget();
        $completedWeek  = (clone $completedBase)->where('completed_at', '>=', $now->copy()->startOfWeek())->count();
        $completedMonth = (clone $completedBase)->where('completed_at', '>=', $now->copy()->startOfMonth())->count();
        $completedAll   = (clone $completedBase)->count();

        $failedAll = AccountAssignment::query()
            ->where('employee_id', $userId)
            ->where('status', AccountAssignment::STATUS_FAILED)
            ->count();

        return [
            Stat::make('المفتوحة الآن', number_format($openTotal))
                ->description('كل الحسابات اللي مازالت في يدك')
                ->descriptionIcon('heroicon-m-inbox-stack')
                ->color('gray'),

            Stat::make('قيد التنفيذ', number_format($inProgress))
                ->description('بدأت الشغل عليها')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning'),

            Stat::make('بانتظار المراجعة', number_format($awaitingReview))
                ->description('رفعت الإثبات، بانتظار المشرف')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('primary'),

            Stat::make('عاجل (+24 ساعة)', number_format($urgent))
                ->description('حسابات مضى عليها يوم أو أكثر')
                ->descriptionIcon('heroicon-m-clock')
                ->color($urgent > 0 ? 'danger' : 'gray'),

            Stat::make('أكملت اليوم', $target !== null
                    ? number_format($completedToday).' / '.number_format($target)
                    : number_format($completedToday))
                ->description(match (true) {
                    $target === null            => 'حسابات وافق عليها المشرف اليوم',
                    $completedToday >= $target  => 'حققت هدفك اليومي',
                    default                     => 'باقي '.number_format($target - $completedToday).' لتحقيق هدفك اليومي',
                })
                ->descriptionIcon($target !== null && $completedToday < $target ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-badge')
                ->color($target !== null && $completedToday < $target ? 'warning' : 'success'),

            Stat::make('هذا الأسبوع', number_format($completedWeek))
                ->description('منذ بداية الأسبوع')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('success'),

            Stat::make('هذا الشهر', number_format($completedMonth))
                ->description('منذ بداية الشهر')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('success'),

            Stat::make('إجمالي مكتمل', number_format($completedAll))
                ->description($failedAll > 0
                    ? number_format($failedAll).' فاشلة'
                    : 'بدون حالات فشل')
                ->descriptionIcon('heroicon-m-trophy')
                ->color('success'),
        ];
    }
}
