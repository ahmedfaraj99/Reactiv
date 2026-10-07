<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\DistributeAccounts;
use App\Models\Account;
use App\Models\AccountAssignment;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bulk distribution hands out ONE kind of account at a time — match and
 * activation-only accounts must never end up mixed in an employee's
 * batch just because they were the oldest available.
 */
class DistributeByMatchesTypeTest extends TestCase
{
    public function test_distribution_only_takes_the_selected_kind(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office = $this->makeOffice($tenant, ['manager_id' => $manager->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        // Oldest first is match accounts — an untyped pick would grab them.
        $old = $this->makeAccount($tenant, ['manager_id' => $manager->id, 'status' => 'available', 'matches_required' => 3]);
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $plainA = $this->makeAccount($tenant, ['manager_id' => $manager->id, 'status' => 'available', 'matches_required' => 0]);
        $plainB = $this->makeAccount($tenant, ['manager_id' => $manager->id, 'status' => 'available', 'matches_required' => 0]);

        $this->actingAsTenantUser($manager);

        Livewire::test(DistributeAccounts::class)
            ->fillForm([
                'matches_type' => 'activation',
                'employee_ids' => [$employee->id],
                'rows'         => [['employee_id' => $employee->id, 'count' => 2]],
            ])
            ->callAction('distribute');

        $assigned = AccountAssignment::where('employee_id', $employee->id)->pluck('account_id')->sort()->values()->all();

        $this->assertSame(collect([$plainA->id, $plainB->id])->sort()->values()->all(), $assigned);
        $this->assertSame('available', $old->fresh()->status);
    }

    public function test_refuses_more_than_the_selected_kind_has_available(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office = $this->makeOffice($tenant, ['manager_id' => $manager->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->makeAccount($tenant, ['manager_id' => $manager->id, 'status' => 'available', 'matches_required' => 3]);
        $this->makeAccount($tenant, ['manager_id' => $manager->id, 'status' => 'available', 'matches_required' => 3]);
        $this->makeAccount($tenant, ['manager_id' => $manager->id, 'status' => 'available', 'matches_required' => 0]);

        $this->actingAsTenantUser($manager);

        // 2 requested, 3 available overall, but only 1 activation-only.
        Livewire::test(DistributeAccounts::class)
            ->fillForm([
                'matches_type' => 'activation',
                'employee_ids' => [$employee->id],
                'rows'         => [['employee_id' => $employee->id, 'count' => 2]],
            ])
            ->callAction('distribute');

        $this->assertSame(0, AccountAssignment::where('employee_id', $employee->id)->count());
    }

    public function test_available_counts_are_per_kind(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $this->makeAccount($tenant, ['manager_id' => $manager->id, 'status' => 'available', 'matches_required' => 3]);
        $this->makeAccount($tenant, ['manager_id' => $manager->id, 'status' => 'available', 'matches_required' => 0]);
        $this->makeAccount($tenant, ['manager_id' => $manager->id, 'status' => 'available', 'matches_required' => 0]);

        $this->actingAsTenantUser($manager);

        $page = Livewire::test(DistributeAccounts::class)->instance();

        $this->assertSame(1, $page->availableCount('matches'));
        $this->assertSame(2, $page->availableCount('activation'));
        $this->assertSame(3, $page->availableCount());
        $this->assertSame('matches', $page->selectedMatchesType());
    }
}
