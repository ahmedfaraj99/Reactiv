<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\UserResource\Pages\ListUsers;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UserResource's "reset_device" row action — the only way, short of
 * editing the DB directly, for a supervisor/manager/owner to clear an
 * employee's bound device_fingerprint after they get a new phone. Only
 * visible once a fingerprint is actually bound; clears it back to null,
 * which BindDeviceFingerprint treats as "first fingerprint ever" (silent
 * rebind, no new_device alert) on the employee's next request.
 */
class DeviceResetTest extends TestCase
{
    public function test_supervisor_can_reset_an_employees_bound_device(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office, [
            'device_fingerprint' => 'abc123fingerprint',
        ]);

        $this->actingAsTenantUser($supervisor);

        Livewire::test(ListUsers::class)
            ->callTableAction('reset_device', $employee);

        $this->assertNull($employee->fresh()->device_fingerprint);
    }

    public function test_reset_device_action_is_hidden_when_no_device_is_bound(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->actingAsTenantUser($supervisor);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('reset_device', $employee);
    }
}
