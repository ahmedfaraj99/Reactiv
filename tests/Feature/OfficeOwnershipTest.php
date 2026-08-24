<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\OfficeResource;
use App\Filament\App\Resources\OfficeResource\Pages\CreateOffice;
use App\Models\Office;
use Tests\TestCase;

/**
 * Offices belong to the managers who run them, not to the tenant
 * owner — the owner's scope is managers (see UserResource), and each
 * manager owns their own office(s). A manager creating an office is
 * always assigned as its manager automatically; they never see or
 * touch another manager's office.
 */
class OfficeOwnershipTest extends TestCase
{
    public function test_owner_cannot_access_offices(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $this->actingAsTenantUser($owner);

        $this->assertFalse(OfficeResource::canAccess());
    }

    public function test_manager_can_access_offices(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $this->actingAsTenantUser($manager);

        $this->assertTrue(OfficeResource::canAccess());
    }

    public function test_manager_only_sees_their_own_offices(): void
    {
        $tenant = $this->makeTenant();
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);

        $officeA = Office::create(['tenant_id' => $tenant->id, 'name' => 'مكتب أ', 'manager_id' => $managerA->id, 'active' => true]);
        Office::create(['tenant_id' => $tenant->id, 'name' => 'مكتب ب', 'manager_id' => $managerB->id, 'active' => true]);

        $this->actingAsTenantUser($managerA);

        $visible = OfficeResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$officeA->id], $visible);
    }

    public function test_creating_an_office_auto_assigns_the_acting_manager(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $this->actingAsTenantUser($manager);

        $page = new class extends CreateOffice
        {
            public function callMutate(array $data): array
            {
                return $this->mutateFormDataBeforeCreate($data);
            }
        };

        $data = $page->callMutate(['name' => 'مكتب جديد', 'active' => true]);

        $this->assertSame($manager->id, $data['manager_id']);
    }
}
