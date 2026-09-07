<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Models\AccountAssignment;
use App\Models\Alert;
use Tests\TestCase;

/**
 * An employee being deactivated or deleted must not leave their
 * in-flight accounts stranded — invisible to the pool, invisible to
 * everyone. UserObserver::releaseInFlightAccounts() is the safety
 * net: pending/in_progress assignments get deleted and the account
 * goes back to 'available'. Anything already awaiting_review,
 * completed, or failed is left untouched — those have a paper trail
 * a human still needs to act on.
 */
class UserObserverTest extends TestCase
{
    public function test_deactivating_an_employee_releases_their_pending_account(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $this->makeAssignment($tenant, $account, $employee, ['status' => AccountAssignment::STATUS_PENDING]);

        $employee->update(['active' => false]);

        $this->assertSame('available', $account->fresh()->status);
        $this->assertSame(0, AccountAssignment::where('account_id', $account->id)->count());
    }

    public function test_deactivating_an_employee_releases_their_in_progress_account(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $this->makeAssignment($tenant, $account, $employee, [
            'status'               => AccountAssignment::STATUS_IN_PROGRESS,
            'psn_totp_generations' => 1,
        ]);

        $employee->update(['active' => false]);

        $this->assertSame('available', $account->fresh()->status);
        $this->assertSame(0, AccountAssignment::where('account_id', $account->id)->count());
    }

    public function test_deactivating_an_employee_does_not_touch_an_awaiting_review_account(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_AWAITING_REVIEW,
            'submitted_at' => now(),
        ]);

        $employee->update(['active' => false]);

        $this->assertSame('assigned', $account->fresh()->status);
        $this->assertNotNull(AccountAssignment::find($assignment->id));
        $this->assertSame(AccountAssignment::STATUS_AWAITING_REVIEW, $assignment->fresh()->status);
    }

    public function test_deactivating_an_employee_does_not_touch_a_failed_account(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status' => AccountAssignment::STATUS_FAILED,
        ]);

        $employee->update(['active' => false]);

        $this->assertSame('assigned', $account->fresh()->status);
        $this->assertNotNull(AccountAssignment::find($assignment->id));
    }

    public function test_updating_an_unrelated_field_does_not_release_accounts(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $this->makeAssignment($tenant, $account, $employee, ['status' => AccountAssignment::STATUS_PENDING]);

        $employee->update(['name' => 'اسم جديد']);

        $this->assertSame('assigned', $account->fresh()->status);
        $this->assertSame(1, AccountAssignment::where('account_id', $account->id)->count());
    }

    public function test_reactivating_an_employee_does_not_release_anything(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office, ['active' => false]);
        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $this->makeAssignment($tenant, $account, $employee, ['status' => AccountAssignment::STATUS_PENDING]);

        $employee->update(['active' => true]);

        $this->assertSame('assigned', $account->fresh()->status);
        $this->assertSame(1, AccountAssignment::where('account_id', $account->id)->count());
    }

    public function test_deleting_an_employee_releases_their_pending_account(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $this->makeAssignment($tenant, $account, $employee, ['status' => AccountAssignment::STATUS_PENDING]);

        $employee->delete();

        $this->assertSame('available', $account->fresh()->status);
        $this->assertSame(0, AccountAssignment::where('account_id', $account->id)->count());
    }

    public function test_deleting_the_only_supervisor_on_an_office_raises_orphan_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant, ['name' => 'مكتب أ']);

        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $this->makeUser($tenant, UserRole::Employee, $office);
        $this->makeUser($tenant, UserRole::Employee, $office);

        $supervisor->delete();

        $alert = Alert::where('tenant_id', $tenant->id)
            ->where('type', AlertType::SupervisorDeleted)
            ->firstOrFail();

        $this->assertSame('high', $alert->severity->value);
        $this->assertSame(2, $alert->payload['employee_count']);
        $this->assertSame($office->id, $alert->payload['office_id']);
    }

    public function test_deleting_a_supervisor_when_another_still_covers_the_office_does_not_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);

        $supervisorA = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $this->makeUser($tenant, UserRole::Supervisor, $office); // co-supervisor
        $this->makeUser($tenant, UserRole::Employee, $office);

        $supervisorA->delete();

        $this->assertSame(
            0,
            Alert::where('tenant_id', $tenant->id)
                ->where('type', AlertType::SupervisorDeleted)
                ->count(),
        );
    }

    public function test_deleting_a_supervisor_with_no_office_does_not_raise_the_alert(): void
    {
        $tenant = $this->makeTenant();
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, null);

        $supervisor->delete();

        $this->assertSame(
            0,
            Alert::where('tenant_id', $tenant->id)
                ->where('type', AlertType::SupervisorDeleted)
                ->count(),
        );
    }

    public function test_deleting_an_employee_does_not_raise_the_supervisor_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $employee->delete();

        $this->assertSame(
            0,
            Alert::where('tenant_id', $tenant->id)
                ->where('type', AlertType::SupervisorDeleted)
                ->count(),
        );
    }
}
