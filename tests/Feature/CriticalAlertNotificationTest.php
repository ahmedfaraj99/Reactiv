<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Alert;
use Tests\TestCase;

/**
 * AlertObserver fires CriticalAlertNotification for urgent alerts. This
 * covers the two delivery paths that matter: the tenant owner always
 * gets a database (in-app bell) notification alongside mail, and an
 * assignment_overdue alert additionally reaches the assignment's own
 * supervisor — not just the owner — since the supervisor is who can
 * actually chase the employee.
 */
class CriticalAlertNotificationTest extends TestCase
{
    public function test_a_critical_alert_leaves_a_database_notification_for_the_owner(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);

        Alert::create([
            'tenant_id' => $tenant->id,
            'type'      => Alert::TYPE_LOGIN_ATTACK,
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
            'type'      => Alert::TYPE_OFF_HOURS,
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
            'type'       => Alert::TYPE_ASSIGNMENT_OVERDUE,
            'severity'   => 'high',
            'message'    => 'تخصيص متأخر',
            'payload'    => ['assignment_id' => $assignment->id],
        ]);

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $supervisor->notifications()->count());
    }
}
