<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AccountAssignment;
use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled sweep (see routes/console.php) that catches assignments
 * nobody is actively watching: an activation still pending/in-progress/
 * awaiting-review 24h+ after it was assigned. Previously the only signal
 * for this was the "عاجل" count on the employee's own dashboard widget —
 * useful only if someone thinks to check it. This turns the same
 * threshold into a push: one Alert per stale assignment, routed to its
 * supervisor (see AlertObserver) so it surfaces without anyone hunting
 * for it.
 */
class EscalateOverdueAssignments implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Hours after assigned_at before an outstanding assignment is overdue. */
    public const OVERDUE_HOURS = 24;

    public function handle(): void
    {
        $threshold = now()->subHours(self::OVERDUE_HOURS);

        AccountAssignment::query()
            ->whereIn('status', [
                AccountAssignment::STATUS_PENDING,
                AccountAssignment::STATUS_IN_PROGRESS,
                AccountAssignment::STATUS_AWAITING_REVIEW,
            ])
            ->where('assigned_at', '<=', $threshold)
            ->whereDoesntHave('account.alerts', function ($q): void {
                // Debounce: one overdue alert per account is enough — it
                // stays visible on the Alerts page until someone resolves
                // it, rather than re-firing every time the sweep runs.
                $q->where('type', Alert::TYPE_ASSIGNMENT_OVERDUE)
                    ->where('resolved', false);
            })
            ->with('account')
            ->chunkById(200, function ($assignments): void {
                foreach ($assignments as $assignment) {
                    $hours = (int) $assignment->assigned_at->diffInHours(now());

                    Alert::create([
                        'tenant_id'  => $assignment->tenant_id,
                        'user_id'    => $assignment->employee_id,
                        'account_id' => $assignment->account_id,
                        'type'       => Alert::TYPE_ASSIGNMENT_OVERDUE,
                        'severity'   => 'high',
                        'message'    => "تخصيص متأخر منذ {$hours} ساعة بلا استكمال",
                        'payload'    => [
                            'assignment_id' => $assignment->id,
                            'hours'         => $hours,
                            'status'        => $assignment->status,
                        ],
                    ]);
                }
            });
    }
}
