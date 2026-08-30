<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Alert;
use App\Notifications\CriticalAlertNotification;
use Tests\TestCase;
use App\Enums\AlertType;

/**
 * AlertObserver fires CriticalAlertNotification for urgent alerts. This
 * covers the delivery paths that matter: the tenant owner always gets a
 * database (in-app bell) notification, mail is gated to a short allowlist
 * of genuinely security-critical types (see CriticalAlertNotification::
 * MAIL_TYPES), and an assignment_overdue alert additionally reaches the
 * assignment's own supervisor — not just the owner — since the supervisor
 * is who can actually chase the employee.
 */
class CriticalAlertNotificationTest extends TestCase
{
    public function test_operational_noise_alerts_stay_in_the_bell_and_do_not_send_mail(): void
    {
        $noisyTypes = [
            [AlertType::TotpLimit,          'medium'],
            [AlertType::AssignmentOverdue,  'high'],
            [AlertType::AssignmentsReleased,'high'],
            [AlertType::HighVolume,         'critical'],
            [AlertType::RepeatReveal,       'high'],
        ];

        foreach ($noisyTypes as [$type, $severity]) {
            $alert = new Alert(['type' => $type, 'severity' => $severity]);

            $this->assertSame(
                ['database'],
                (new CriticalAlertNotification($alert))->via(new \stdClass),
                "expected {$type->value} to stay in the bell only"
            );
        }
    }

    public function test_security_critical_alerts_reach_mail(): void
    {
        $mailTypes = [
            [AlertType::LoginAttack,     'critical'],
            [AlertType::EmergencyFreeze, 'critical'],
            [AlertType::SuspiciousSpeed, 'critical'],
            [AlertType::DuplicateProof,  'critical'],
            [AlertType::NewDevice,       'high'],
        ];

        foreach ($mailTypes as [$type, $severity]) {
            $alert = new Alert(['type' => $type, 'severity' => $severity]);

            $this->assertSame(
                ['mail', 'database'],
                (new CriticalAlertNotification($alert))->via(new \stdClass),
                "expected {$type->value} to be mailed"
            );
        }
    }

    public function test_a_critical_alert_leaves_a_database_notification_for_the_owner(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);

        Alert::create([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::LoginAttack,
            'severity'  => 'critical',
            'message'   => 'محاولات دخول مشبوهة',
        ]);

        $this->assertSame(1, $owner->notifications()->count());
        $data = $owner->notifications()->first()->data;
        $this->assertSame('critical', $data['severity']);
    }

    public function test_a_low_severity_alert_does_not_notify_anyone(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);

        Alert::create([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::OffHours,
            'severity'  => 'medium',
            'message'   => 'نشاط خارج ساعات العمل',
        ]);

        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_assignment_overdue_alert_notifies_both_owner_and_supervisor(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = AccountAssignment::create([
            'tenant_id'     => $tenant->id,
            'account_id'    => $account->id,
            'employee_id'   => $employee->id,
            'supervisor_id' => $supervisor->id,
            'status'        => AccountAssignment::STATUS_IN_PROGRESS,
            'assigned_at'   => now()->subHours(30),
        ]);

        Alert::create([
            'tenant_id'  => $tenant->id,
            'user_id'    => $employee->id,
            'account_id' => $account->id,
            'type'       => AlertType::AssignmentOverdue,
            'severity'   => 'high',
            'message'    => 'تخصيص متأخر',
            'payload'    => ['assignment_id' => $assignment->id],
        ]);

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $supervisor->notifications()->count());
    }
}
