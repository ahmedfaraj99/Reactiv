<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\UserResource;
use App\Models\User;
use Tests\TestCase;

/**
 * Each tier manages only the tier directly below it (owner->manager->
 * supervisor->employee, no skipping). UserResource::managedRole() is
 * the single source of truth CreateUser relies on to assign a role —
 * it must never be influenced by client-submitted form data, only by
 * who is actually logged in.
 */
class RoleHierarchyTest extends TestCase
{
    public function test_owner_manages_managers_only(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);

        $this->actingAsTenantUser($owner);

        $this->assertSame(UserRole::Manager, UserResource::managedRole());
    }

    public function test_manager_manages_supervisors_only(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $this->actingAsTenantUser($manager);

        $this->assertSame(UserRole::Supervisor, UserResource::managedRole());
    }

    public function test_supervisor_manages_employees_only(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);

        $this->actingAsTenantUser($supervisor);

        $this->assertSame(UserRole::Employee, UserResource::managedRole());
    }

    public function test_employee_manages_no_one(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->actingAsTenantUser($employee);

        $this->assertNull(UserResource::managedRole());
    }

    public function test_manager_creating_a_user_always_gets_supervisor_role_even_if_tampered(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $this->actingAsTenantUser($manager);

        $page = new class extends \App\Filament\App\Resources\UserResource\Pages\CreateUser {
            public function callHandleRecordCreation(array $data): User
            {
                return $this->handleRecordCreation($data);
            }
        };

        $created = $page->callHandleRecordCreation([
            'name'     => 'مستخدم جديد',
            'email'    => 'new-'.uniqid().'@test.local',
            'password' => 'Password!123',
            'active'   => true,
            'role'     => UserRole::TenantOwner->value,
        ]);

        $this->assertTrue($created->hasRole(UserRole::Supervisor->value));
        $this->assertFalse($created->hasRole(UserRole::TenantOwner->value));
        $this->assertFalse($created->hasRole(UserRole::Manager->value));
    }

    public function test_supervisor_can_create_employees(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $this->actingAsTenantUser($supervisor);

        $page = new class extends \App\Filament\App\Resources\UserResource\Pages\CreateUser {
            public function callHandleRecordCreation(array $data): User
            {
                return $this->handleRecordCreation($data);
            }
        };

        $created = $page->callHandleRecordCreation([
            'name'     => 'موظف جديد',
            'email'    => 'emp-'.uniqid().'@test.local',
            'password' => 'Password!123',
            'active'   => true,
        ]);

        $this->assertTrue($created->hasRole(UserRole::Employee->value));
        $this->assertSame($tenant->id, $created->tenant_id);
    }

    public function test_employee_is_blocked_from_creating_any_user(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $this->actingAsTenantUser($employee);

        $page = new class extends \App\Filament\App\Resources\UserResource\Pages\CreateUser {
            public function callHandleRecordCreation(array $data): User
            {
                return $this->handleRecordCreation($data);
            }
        };

        $this->expectException(\Illuminate\Validation\UnauthorizedException::class);

        $page->callHandleRecordCreation([
            'name'     => 'محاولة غير مصرح بها',
            'email'    => 'blocked-'.uniqid().'@test.local',
            'password' => 'Password!123',
            'active'   => true,
        ]);
    }
}
