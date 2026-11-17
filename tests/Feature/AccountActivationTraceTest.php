<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource\Pages\ListAccounts;
use App\Models\AccountAssignment;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * When an "activated" account turns out not to be, the owner must be able
 * to go from its email to who activated it and who approved that.
 */
class AccountActivationTraceTest extends TestCase
{
    public function test_full_email_search_finds_the_account_and_a_partial_one_does_not(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $wanted = $this->makeAccount($tenant, ['email' => 'wanted@example.com']);
        $viaEa = $this->makeAccount($tenant, ['ea_email' => 'ea.wanted@example.com']);
        $other = $this->makeAccount($tenant, ['email' => 'other@example.com']);

        $this->actingAsTenantUser($owner);

        Livewire::test(ListAccounts::class)
            ->searchTable('WANTED@example.com')
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$viaEa, $other])
            ->searchTable('ea.wanted@example.com')
            ->assertCanSeeTableRecords([$viaEa])
            ->searchTable('wanted@')
            ->assertCanNotSeeTableRecords([$wanted, $viaEa, $other]);
    }

    public function test_email_lookups_ignore_the_order_type_switch(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $activationOnly = $this->makeAccount($tenant, ['email' => 'solo@example.com', 'matches_required' => 0]);

        $this->actingAsTenantUser($owner);

        Livewire::test(ListAccounts::class)
            ->set('matchesType', 'matches')
            ->assertCanNotSeeTableRecords([$activationOnly])
            ->searchTable('solo@example.com')
            ->assertCanSeeTableRecords([$activationOnly])
            ->searchTable('')
            ->filterTable('exact_email', ['email' => 'solo@example.com'])
            ->assertCanSeeTableRecords([$activationOnly]);
    }

    public function test_it_names_who_activated_and_who_approved_even_after_they_are_removed(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office, ['name' => 'الموظف المنفذ']);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office, ['name' => 'المشرف المعتمد']);

        $account = $this->makeAccount($tenant, ['status' => 'activated']);
        $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'reviewed_by'  => $supervisor->id,
            'reviewed_at'  => now(),
            'completed_at' => now(),
        ]);

        $employee->delete();
        $supervisor->delete();

        $this->actingAsTenantUser($owner);

        Livewire::test(ListAccounts::class)
            ->set('matchesType', 'all')
            ->assertTableColumnStateSet('assignment.employeeWithTrashed.name', 'الموظف المنفذ', $account->getKey())
            ->assertTableColumnStateSet('approved_by', 'المشرف المعتمد', $account->getKey())
            ->mountTableAction('activationHistory', $account)
            ->assertSee('الموظف المنفذ')
            ->assertSee('المشرف المعتمد');
    }

    public function test_history_shows_the_proof_or_says_why_it_cannot(): void
    {
        Storage::fake('local');

        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $make = function (?string $proof) use ($tenant, $employee): array {
            $account = $this->makeAccount($tenant, ['status' => 'activated']);
            $assignment = $this->makeAssignment($tenant, $account, $employee, [
                'status'       => AccountAssignment::STATUS_COMPLETED,
                'proof_path'   => $proof,
                'submitted_at' => now(),
            ]);

            return [$account, $assignment];
        };

        Storage::disk('local')->put('proofs/here.jpg', 'bytes');
        [$withProof, $assignment] = $make('proofs/here.jpg');
        [$fileGone] = $make('proofs/gone.jpg');
        [$noProof] = $make(null);

        $this->actingAsTenantUser($owner);

        $page = fn () => Livewire::test(ListAccounts::class)->set('matchesType', 'all');

        $page()->mountTableAction('activationHistory', $withProof)
            ->assertSeeHtml('<img src="'.e(route('proof.show', $assignment)).'"');
        $page()->mountTableAction('activationHistory', $fileGone)
            ->assertSee('ملف الإثبات غير موجود على الخادم.');
        $page()->mountTableAction('activationHistory', $noProof)
            ->assertSee('لم يُرفع إثبات لهذا التفعيل.');
    }

    public function test_supervisor_can_still_open_the_proof_of_a_removed_employee(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('proofs/here.jpg', 'bytes');

        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $assignment = $this->makeAssignment($tenant, $this->makeAccount($tenant), $employee, [
            'status'     => AccountAssignment::STATUS_COMPLETED,
            'proof_path' => 'proofs/here.jpg',
        ]);

        $employee->delete();

        $this->actingAs($supervisor)->get(route('proof.show', $assignment))->assertOk();
    }

    public function test_a_rejection_does_not_name_an_approver(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);

        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $this->makeAssignment($tenant, $account, $employee, [
            'status'      => AccountAssignment::STATUS_IN_PROGRESS,
            'reviewed_by' => $supervisor->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAsTenantUser($owner);

        Livewire::test(ListAccounts::class)
            ->set('matchesType', 'all')
            ->assertTableColumnStateSet('approved_by', null, $account->getKey());
    }
}
