<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountAdminLogResource;
use Tests\TestCase;

/**
 * The archive/permanent-delete audit log is sensitive (it's the trail
 * for irreversible actions on client data) — only the tenant owner and
 * the manager who actually runs the failed-account export/delete flow
 * should be able to read it. Supervisors and employees must not.
 */
class AccountAdminLogAccessTest extends TestCase
{
    public function test_owner_can_access_the_admin_log(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $this->actingAsTenantUser($owner);

        $this->assertTrue(AccountAdminLogResource::canAccess());
    }

    public function test_manager_can_access_the_admin_log(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $this->actingAsTenantUser($manager);

        $this->assertTrue(AccountAdminLogResource::canAccess());
    }

    public function test_supervisor_cannot_access_the_admin_log(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $this->actingAsTenantUser($supervisor);

        $this->assertFalse(AccountAdminLogResource::canAccess());
    }

    public function test_employee_cannot_access_the_admin_log(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $this->actingAsTenantUser($employee);

        $this->assertFalse(AccountAdminLogResource::canAccess());
    }

    public function test_supervisor_get_request_to_the_admin_log_page_is_forbidden(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $this->actingAsTenantUser($supervisor);

        $response = $this->get(
            \App\Filament\App\Resources\AccountAdminLogResource\Pages\ListAccountAdminLogs::getUrl()
        );

        $response->assertForbidden();
    }
}
