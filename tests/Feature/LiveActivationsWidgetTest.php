<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Widgets\LiveActivationsWidget;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * Real-time board of what employees are working on right now.
 * Scoping matches the rest of the tenant-scoped widgets: owner sees
 * every in-flight in the tenant, manager sees managed offices only,
 * supervisor sees their single office only. Completed/awaiting-review
 * rows never appear here — this is intentionally "IN_PROGRESS only".
 */
class LiveActivationsWidgetTest extends TestCase
{
    /** @return \Illuminate\Support\Collection<int,AccountAssignment> */
    private function rows(LiveActivationsWidget $widget)
    {
        $method = new \ReflectionMethod(LiveActivationsWidget::class, 'liveQuery');
        $method->setAccessible(true);
        return $method->invoke($widget)->get();
    }

    public function test_only_in_progress_assignments_appear_on_the_live_board(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@x.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@x.com')]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@x.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@x.com')]);
        $accountC = $this->makeAccount($tenant, ['email' => 'c@x.com', 'email_fingerprint' => \App\Models\Account::fingerprint('c@x.com')]);

        $inProgress = $this->makeAssignment($tenant, $accountA, $employee, ['status' => AccountAssignment::STATUS_IN_PROGRESS]);
        $this->makeAssignment($tenant, $accountB, $employee, ['status' => AccountAssignment::STATUS_AWAITING_REVIEW]);
        $this->makeAssignment($tenant, $accountC, $employee, ['status' => AccountAssignment::STATUS_COMPLETED]);

        $this->actingAsTenantUser($owner);
        $rows = $this->rows(new LiveActivationsWidget());

        $this->assertCount(1, $rows);
        $this->assertSame($inProgress->id, $rows->first()->id);
    }

    public function test_supervisor_only_sees_in_flight_activations_from_their_own_office(): void
    {
        $tenant = $this->makeTenant();
        $officeA = $this->makeOffice($tenant, ['name' => 'A']);
        $officeB = $this->makeOffice($tenant, ['name' => 'B']);
        $supervisorA = $this->makeUser($tenant, UserRole::Supervisor, $officeA);
        $employeeA = $this->makeUser($tenant, UserRole::Employee, $officeA);
        $employeeB = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@x.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@x.com')]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@x.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@x.com')]);

        $inScope = $this->makeAssignment($tenant, $accountA, $employeeA, ['status' => AccountAssignment::STATUS_IN_PROGRESS]);
        $this->makeAssignment($tenant, $accountB, $employeeB, ['status' => AccountAssignment::STATUS_IN_PROGRESS]);

        $this->actingAsTenantUser($supervisorA);
        $rows = $this->rows(new LiveActivationsWidget());

        $this->assertCount(1, $rows);
        $this->assertSame($inScope->id, $rows->first()->id);
    }
}
