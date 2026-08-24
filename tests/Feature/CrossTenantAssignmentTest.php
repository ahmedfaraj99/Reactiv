<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\Activation;
use App\Filament\App\Resources\AccountResource;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * Locks in the CRITICAL fixes from the 2026-08-21 security review:
 *   #1  AccountResource::assignAccounts must refuse an employee_id that
 *       does not belong to the caller's tenant/office scope.
 *   #2  Activation::mount must 404 on an assignment whose tenant does
 *       not match the acting user's tenant, even if employee_id lines up.
 */
class CrossTenantAssignmentTest extends TestCase
{
    public function test_supervisor_cannot_assign_accounts_to_employee_in_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $officeA = $this->makeOffice($tenantA);
        $managerA = $this->makeUser($tenantA, UserRole::Manager, $officeA);
        $supervisorA = $this->makeUser($tenantA, UserRole::Supervisor, $officeA);

        $tenantB = $this->makeTenant();
        $officeB = $this->makeOffice($tenantB);
        $employeeB = $this->makeUser($tenantB, UserRole::Employee, $officeB);

        $account = $this->makeAccount($tenantA, ['manager_id' => $managerA->id]);

        $this->actingAsTenantUser($supervisorA);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        AccountResource::assignAccounts(collect([$account]), $employeeB->id);
    }

    public function test_employee_cannot_open_activation_for_another_tenants_assignment(): void
    {
        $tenantA = $this->makeTenant();
        $officeA = $this->makeOffice($tenantA);
        $employeeA = $this->makeUser($tenantA, UserRole::Employee, $officeA);
        $accountA = $this->makeAccount($tenantA);
        $foreignAssignment = $this->makeAssignment($tenantA, $accountA, $employeeA);

        // A parallel-universe user in tenant B whose id happens to match:
        // the route model binding loads by primary key, so employee_id
        // matches by coincidence. Only the tenant check saves us.
        $tenantB = $this->makeTenant();
        $officeB = $this->makeOffice($tenantB);
        $employeeB = $this->makeUser($tenantB, UserRole::Employee, $officeB, [
            'id' => $employeeA->id + 10_000,
        ]);
        AccountAssignment::query()->where('id', $foreignAssignment->id)->update([
            'employee_id' => $employeeB->id,
        ]);

        $this->actingAsTenantUser($employeeB);

        $page = new Activation();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $page->mount($foreignAssignment->fresh());
    }
}
