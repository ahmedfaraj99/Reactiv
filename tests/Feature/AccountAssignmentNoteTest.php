<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource\Pages\ListAccounts;
use App\Models\AccountAssignment;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AccountResource's "note" row action — a free-text channel a
 * manager/supervisor uses to leave the employee working an account a
 * note (e.g. "retry with the backup code"), which then shows up
 * read-only on the employee's own "حساباتي" list (MyAccounts). Reuses
 * AccountAssignment.notes, the same column the system already writes to
 * on a wrong-data failure — so this only makes sense while manageable,
 * same scoping as every other per-row action on this resource.
 */
class AccountAssignmentNoteTest extends TestCase
{
    public function test_supervisor_can_leave_a_note_on_an_assignment_in_their_office(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $this->makeAssignment($tenant, $account, $employee, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS,
        ]);

        $this->actingAsTenantUser($supervisor);

        Livewire::test(ListAccounts::class)
            ->callTableAction('note', $account, data: ['notes' => 'جرّب كود الاحتياط الأول']);

        $this->assertSame('جرّب كود الاحتياط الأول', $account->assignment()->first()->notes);
    }

    public function test_supervisor_cannot_see_the_note_action_for_an_account_outside_their_office(): void
    {
        $tenant = $this->makeTenant();
        $officeA = $this->makeOffice($tenant, ['name' => 'مكتب أ']);
        $officeB = $this->makeOffice($tenant, ['name' => 'مكتب ب']);
        $supervisorA = $this->makeUser($tenant, UserRole::Supervisor, $officeA);
        $employeeB = $this->makeUser($tenant, UserRole::Employee, $officeB);
        $account = $this->makeAccount($tenant);
        $this->makeAssignment($tenant, $account, $employeeB, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS,
        ]);

        $this->actingAsTenantUser($supervisorA);

        Livewire::test(ListAccounts::class)
            ->assertTableActionHidden('note', $account);
    }

    public function test_note_action_is_hidden_for_an_unassigned_account(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $account = $this->makeAccount($tenant);

        $this->actingAsTenantUser($owner);

        Livewire::test(ListAccounts::class)
            ->assertTableActionHidden('note', $account);
    }
}
