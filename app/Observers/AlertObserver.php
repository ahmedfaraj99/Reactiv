<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\User;
use App\Notifications\CriticalAlertNotification;
use Illuminate\Support\Facades\Log;

/**
 * Pushes a real notification to the tenant owner the moment an alert
 * worth acting on quickly is created — high/critical severity, or a
 * TOTP-limit request (an employee is actively blocked waiting on it).
 * Everything else stays visible on the Alerts page without paging anyone.
 */
class AlertObserver
{
    /**
     * Types that go into the security audit trail (see the `security`
     * channel in config/logging.php). Kept in sync with — but decoupled
     * from — the mail allowlist: the audit trail wants to record every
     * security-relevant event even if we chose not to mail on it later.
     */
    private const SECURITY_LOG_TYPES = [
        AlertType::LoginAttack,
        AlertType::NewDevice,
        AlertType::SuspiciousSpeed,
        AlertType::DuplicateProof,
        AlertType::EmergencyFreeze,
    ];

    public function created(Alert $alert): void
    {
        $this->auditLog($alert);

        if (! $this->isUrgent($alert)) {
            return;
        }

        $owner = User::query()
            ->where('tenant_id', $alert->tenant_id)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::TenantOwner->value))
            ->first();

        $owner?->notify(new CriticalAlertNotification($alert));

        if ($alert->type === AlertType::AssignmentOverdue) {
            $this->notifyAssignmentSupervisor($alert, except: $owner);
        }

        if ($alert->type === AlertType::AssignmentsReleased) {
            $this->notifyPoolManager($alert, except: $owner);
        }
    }

    /**
     * Write a structured line to the security channel. Everything is one
     * JSON object per event so a `grep '"type":"login_attack"'` (or a
     * SIEM query) returns real records, not free-form prose.
     */
    private function auditLog(Alert $alert): void
    {
        if (! in_array($alert->type, self::SECURITY_LOG_TYPES, true)) {
            return;
        }

        Log::channel('security')->info('alert.raised', [
            'alert_id'   => $alert->id,
            'type'       => $alert->type->value,
            'severity'   => $alert->severity->value,
            'tenant_id'  => $alert->tenant_id,
            'user_id'    => $alert->user_id,
            'account_id' => $alert->account_id,
            'message'    => $alert->message,
            'payload'    => $alert->payload,
            'at'         => $alert->created_at?->toIso8601String(),
        ]);
    }

    private function isUrgent(Alert $alert): bool
    {
        return in_array($alert->severity, [AlertSeverity::Critical, AlertSeverity::High], true)
            || $alert->type === AlertType::TotpLimit
            || $alert->type === AlertType::BackupCodesReveal;
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
