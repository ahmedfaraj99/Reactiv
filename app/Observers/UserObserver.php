<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AccountAssignment;
use App\Models\Alert;
use App\Models\User;
use App\Enums\AlertType;

/**
 * An employee being deleted or deactivated shouldn't leave their
 * in-flight accounts stuck forever — invisible to the pool, invisible to
 * everyone. Anything not yet submitted for review is released back to
 * "available" so a supervisor can hand it to someone else. Assignments
 * already awaiting review, completed, or failed are left alone — those
 * have a paper trail a human still needs to act on.
 *
 * A silent release is worse than no release: 30 accounts quietly appear
 * in the pool with no one told to redistribute them. After the release,
 * emit one Alert per affected manager pool so the redistribution work
 * actually surfaces (AlertObserver routes it to the manager + owner).
 */
class UserObserver
{
    public function deleting(User $user): void
    {
        $this->releaseInFlightAccounts($user);
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('active') && ! $user->active) {
            $this->releaseInFlightAccounts($user);
        }
    }

    private function releaseInFlightAccounts(User $user): void
    {
        $assignments = AccountAssignment::query()
            ->where('employee_id', $user->id)
            ->whereIn('status', [AccountAssignment::STATUS_PENDING, AccountAssignment::STATUS_IN_PROGRESS])
            ->with('account')
            ->get();

        if ($assignments->isEmpty()) {
            return;
        }

        // Group by pool owner so a manager gets one alert per pool that
        // was affected, not one per account. Nullable manager_id (very
        // rare — accounts uploaded before that column existed) is kept
        // as a distinct 0-key bucket so nothing goes missing silently.
        $countsByManager = [];

        foreach ($assignments as $assignment) {
            $account = $assignment->account;
            $managerId = (int) ($account?->manager_id ?? 0);
            $countsByManager[$managerId] = ($countsByManager[$managerId] ?? 0) + 1;

            $assignment->delete();
            $account?->update(['status' => 'available']);
        }

        // The employee's name is captured into the message now — by the
        // time a queued notification runs, the user may already be
        // soft-deleted and the default query scope would hide them.
        $employeeName = $user->name;

        foreach ($countsByManager as $managerId => $count) {
            Alert::create([
                'tenant_id' => $user->tenant_id,
                'user_id'   => $user->id,
                'type'      => AlertType::AssignmentsReleased,
                'severity'  => 'high',
                'message'   => "أُعيد {$count} حساباً إلى المخزون بعد تعطيل الموظف {$employeeName} — تحتاج إعادة توزيع",
                'payload'   => [
                    'count'      => $count,
                    'manager_id' => $managerId ?: null,
                ],
            ]);
        }
    }
}
