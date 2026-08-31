<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use Tests\TestCase;

/**
 * User::visibleEmployeeIds() is the single choke point behind the "scope
 * this list to employees I can see" filter that AlertResource,
 * AssignmentReviewResource, and RevealLogResource share. Each role's
 * scope is asserted directly rather than round-tripping through Filament.
 */
class VisibleEmployeeIdsTest extends TestCase
{
    public function test_owner_sees_every_employee_in_the_tenant(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $officeA = $this->makeOffice($tenant);
        $officeB = $this->makeOffice($tenant);
        $eA = $this->makeUser($tenant, UserRole::Employee, $officeA);
        $eB = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $ids = $owner->visibleEmployeeIds();

        $this->assertEqualsCanonicalizing([$eA->id, $eB->id], $ids->all());
    }

    public function test_manager_sees_only_employees_in_offices_they_manage(): void
    {
        $tenant = $this->makeTenant();
        $officeMine = $this->makeOffice($tenant);
        $officeOther = $this->makeOffice($tenant);
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $officeMine->update(['manager_id' => $manager->id]);
        $mine = $this->makeUser($tenant, UserRole::Employee, $officeMine);
        $this->makeUser($tenant, UserRole::Employee, $officeOther);

        $ids = $manager->fresh()->visibleEmployeeIds();

        $this->assertSame([$mine->id], $ids->all());
    }

    public function test_supervisor_sees_only_employees_in_their_own_office(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $otherOffice = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $mine = $this->makeUser($tenant, UserRole::Employee, $office);
        $this->makeUser($tenant, UserRole::Employee, $otherOffice);

        $ids = $supervisor->visibleEmployeeIds();

        $this->assertSame([$mine->id], $ids->all());
    }

    public function test_employee_sees_nobody(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $this->makeUser($tenant, UserRole::Employee, $office);

        $this->assertTrue($employee->visibleEmployeeIds()->isEmpty());
    }
}
