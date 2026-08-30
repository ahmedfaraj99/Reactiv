<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\UserRole;
use App\Models\AccountAssignment;
use App\Models\Alert;
use Tests\TestCase;
use App\Enums\AlertType;

/**
 * When an employee gets deactivated (or deleted), UserObserver silently
 * released their in-flight accounts back to the pool — those accounts
 * would then sit there until someone happened to notice. This test suite
 * covers the new push side of the release: one Alert per affected pool
 * manager, routed via AlertObserver to both the owner and the manager
 * whose batch the accounts came from, so redistribution actually
 * surfaces.
 */
class AssignmentsReleasedAlertTest extends TestCase
{
    public function test_deactivating_an_employee_emits_a_release_alert_with_the_count(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office = $this->makeOffice($tenant, ['manager_id' => $manager->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@example.com'), 'status' => 'assigned', 'manager_id' => $manager->id]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@example.com'), 'status' => 'assigned', 'manager_id' => $manager->id]);
        $this->makeAssignment($tenant, $accountA, $employee, ['status' => AccountAssignment::STATUS_PENDING]);
        $this->makeAssignment($tenant, $accountB, $employee, ['status' => AccountAssignment::STATUS_IN_PROGRESS]);

        $employee->update(['active' => false]);

        $alert = Alert::where('type', AlertType::AssignmentsReleased)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AlertSeverity::High, $alert->severity);
        $this->assertSame(2, $alert->payload['count']);
        $this->assertSame($manager->id, $alert->payload['manager_id']);
        $this->assertStringContainsString($employee->name, (string) $alert->message);
    }

    public function test_no_alert_when_the_employee_had_no_in_flight_accounts(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $employee->update(['active' => false]);

        $this->assertSame(0, Alert::where('type', AlertType::AssignmentsReleased)->count());
    }

    public function test_alerts_are_split_per_pool_when_accounts_came_from_two_managers(): void
    {
        $tenant = $this->makeTenant();
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);
        $office = $this->makeOffice($tenant, ['manager_id' => $managerA->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@example.com'), 'manager_id' => $managerA->id]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@example.com'), 'manager_id' => $managerB->id]);
        $this->makeAssignment($tenant, $accountA, $employee, ['status' => AccountAssignment::STATUS_PENDING]);
        $this->makeAssignment($tenant, $accountB, $employee, ['status' => AccountAssignment::STATUS_PENDING]);

        $employee->update(['active' => false]);

        $this->assertSame(2, Alert::where('type', AlertType::AssignmentsReleased)->count());
        $this->assertSame(1, Alert::where('type', AlertType::AssignmentsReleased)->where('payload->manager_id', $managerA->id)->count());
        $this->assertSame(1, Alert::where('type', AlertType::AssignmentsReleased)->where('payload->manager_id', $managerB->id)->count());
    }

    public function test_release_alert_notifies_both_the_owner_and_the_pool_manager(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office = $this->makeOffice($tenant, ['manager_id' => $manager->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $account = $this->makeAccount($tenant, ['status' => 'assigned', 'manager_id' => $manager->id]);
        $this->makeAssignment($tenant, $account, $employee, ['status' => AccountAssignment::STATUS_PENDING]);

        $employee->update(['active' => false]);

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $manager->notifications()->count());
    }
}
