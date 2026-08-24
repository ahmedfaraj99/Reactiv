<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountAssignment;
use App\Models\Alert;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Assembles the weekly summary for one office over the ISO week
 * containing $reference (default: previous week). Pure data — the PDF
 * layer only cares about the array it returns.
 */
class WeeklyOfficeReport
{
    /**
     * @return array{
     *   office: Office,
     *   period: array{start: CarbonImmutable, end: CarbonImmutable, iso_week: string},
     *   totals: array<string,int>,
     *   employees: Collection<int,object>,
     *   suspicious: array{alerts: int, by_type: array<string,int>, sample: Collection<int,Alert>},
     *   accounts_used: int,
     *   matches_total: int,
     * }
     */
    public function build(Office $office, ?CarbonImmutable $reference = null): array
    {
        $reference ??= CarbonImmutable::now()->subWeek();
        $start = $reference->startOfWeek()->startOfDay();
        $end   = $reference->endOfWeek()->endOfDay();

        $employeeIds = $office->users()->pluck('id');

        $assignments = AccountAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('assigned_at', [$start, $end])
            ->with(['account', 'employee'])
            ->get();

        $totals = [
            'assigned'         => $assignments->count(),
            'completed'        => $assignments->where('status', AccountAssignment::STATUS_COMPLETED)->count(),
            'awaiting_review'  => $assignments->where('status', AccountAssignment::STATUS_AWAITING_REVIEW)->count(),
            'failed'           => $assignments->where('status', AccountAssignment::STATUS_FAILED)->count(),
            'in_progress'      => $assignments->where('status', AccountAssignment::STATUS_IN_PROGRESS)->count(),
        ];

        // "Matches actually played" only makes sense for completed
        // assignments on accounts that required matches — the number
        // required is what the employee had to prove they hit.
        $matchesTotal = $assignments
            ->where('status', AccountAssignment::STATUS_COMPLETED)
            ->sum(fn (AccountAssignment $a) => (int) ($a->account->matches_required ?? 0));

        $employees = $assignments
            ->groupBy('employee_id')
            ->map(function (Collection $group) {
                $first = $group->first();
                return (object) [
                    'id'         => $first->employee_id,
                    'name'       => $first->employee?->name ?? '—',
                    'assigned'   => $group->count(),
                    'completed'  => $group->where('status', AccountAssignment::STATUS_COMPLETED)->count(),
                    'failed'     => $group->where('status', AccountAssignment::STATUS_FAILED)->count(),
                    'matches'    => $group->where('status', AccountAssignment::STATUS_COMPLETED)
                        ->sum(fn (AccountAssignment $a) => (int) ($a->account->matches_required ?? 0)),
                ];
            })
            ->sortByDesc('completed')
            ->values();

        $suspicious = Alert::query()
            ->where('tenant_id', $office->tenant_id)
            ->whereIn('user_id', $employeeIds)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        return [
            'office' => $office,
            'period' => [
                'start'    => $start,
                'end'      => $end,
                'iso_week' => $start->format('o-\WW'),
            ],
            'totals'        => $totals,
            'employees'     => $employees,
            'accounts_used' => $assignments->pluck('account_id')->unique()->count(),
            'matches_total' => (int) $matchesTotal,
            'suspicious'    => [
                'alerts'  => $suspicious->count(),
                'by_type' => $suspicious->groupBy('type')->map->count()->toArray(),
                'sample'  => $suspicious->sortByDesc('created_at')->take(10)->values(),
            ],
        ];
    }
}
