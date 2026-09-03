<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\Activation;
use App\Models\AccountAssignment;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proof-photo requirement is now per-employee: users.requires_proof
 * (default true). Managers whitelist trusted employees by turning it
 * off. The Activation "complete" action must honor that flag on both
 * the form field (->required) and the action closure (server-side
 * re-check), so a hand-crafted Livewire payload can't bypass it.
 */
class PerEmployeeProofRequirementTest extends TestCase
{
    public function test_new_employees_default_to_requiring_proof(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->assertTrue($employee->requires_proof);
    }

    public function test_completing_without_proof_is_refused_when_employee_requires_proof(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        // Explicit for clarity — default is true, but a future default
        // change shouldn't silently break this test's assertion.
        $employee->update(['requires_proof' => true]);

        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'                  => AccountAssignment::STATUS_IN_PROGRESS,
            'credentials_revealed_at' => now(),
        ]);

        $this->actingAsTenantUser($employee);

        Livewire::test(Activation::class, ['assignment' => $assignment])
            ->callAction('complete', data: ['proof_path' => null]);

        $this->assertSame(
            AccountAssignment::STATUS_IN_PROGRESS,
            $assignment->fresh()->status,
            'The assignment must NOT move to awaiting_review when proof was required and omitted.',
        );
        $this->assertNull($assignment->fresh()->submitted_at);
    }

    public function test_completing_without_proof_succeeds_when_employee_is_trusted(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $employee->update(['requires_proof' => false]);

        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'                  => AccountAssignment::STATUS_IN_PROGRESS,
            'credentials_revealed_at' => now(),
        ]);

        $this->actingAsTenantUser($employee);

        Livewire::test(Activation::class, ['assignment' => $assignment])
            ->callAction('complete', data: ['proof_path' => null]);

        $this->assertSame(
            AccountAssignment::STATUS_AWAITING_REVIEW,
            $assignment->fresh()->status,
            'Trusted employee (requires_proof=false) may submit without a photo.',
        );
        $this->assertNotNull($assignment->fresh()->submitted_at);
    }
}
