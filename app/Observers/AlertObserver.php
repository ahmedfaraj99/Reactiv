<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\User;
use App\Notifications\CriticalAlertNotification;

/**
 * Pushes a real notification to the tenant owner the moment an alert
 * worth acting on quickly is created — high/critical severity, or a
 * TOTP-limit request (an employee is actively blocked waiting on it).
 * Everything else stays visible on the Alerts page without paging anyone.
 */
class AlertObserver
{
    public function created(Alert $alert): void
    {
        if (! $this->isUrgent($alert)) {
            return;
        }

        $owner = User::query()
            ->where('tenant_id', $alert->tenant_id)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::TenantOwner->value))
            ->first();

        $owner?->notify(new CriticalAlertNotification($alert));

        if ($alert->type === Alert::TYPE_ASSIGNMENT_OVERDUE) {
            $this->notifyAssignmentSupervisor($alert, except: $owner);
        }

        if ($alert->type === Alert::TYPE_ASSIGNMENTS_RELEASED) {
            $this->notifyPoolManager($alert, except: $owner);
        }
    }

    private function isUrgent(Alert $alert): bool
    {
        return in_array($alert->severity, ['critical', 'high'], true)
            || $alert->type === Alert::TYPE_TOTP_LIMIT;
    }

    /**
     * The tenant owner shouldn't be the only one who hears about a stuck
     * assignment — the supervisor who actually owns that employee's queue
     * is the one who can chase it up. Skip if they're the same person as
     * $except (already notified above) to avoid a duplicate mail/bell.
     */
    private function notifyAssignmentSupervisor(Alert $alert, ?User $except): void
    {
        $supervisor = $alert->account?->assignment?->supervisor;

        if ($supervisor === null || $supervisor->id === $except?->id) {
            return;
        }

        $supervisor->notify(new CriticalAlertNotification($alert));
    }

    /**
     * The pool manager owns the batch these accounts came from — they're
     * the one who has to actually redistribute the released accounts.
     * Silent otherwise: they're the one with the work to do.
     */
    private function notifyPoolManager(Alert $alert, ?User $except): void
    {
        $managerId = $alert->payload['manager_id'] ?? null;
        if ($managerId === null) {
            return;
        }

        $manager = User::find($managerId);
        if ($manager === null || $manager->id === $except?->id) {
            return;
        }

        $manager->notify(new CriticalAlertNotification($alert));
    }
}
