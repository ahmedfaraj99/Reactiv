<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\MyAccounts;
use App\Filament\App\Resources\AccountResource\Pages\ListAccounts;
use App\Models\AccountAssignment;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "الكل | بمباريات | تفعيل فقط" switch on the accounts list and on
 * the employee's queue — match and activation-only orders are different
 * jobs at the console, so staff must be able to look at one kind alone.
 */
class MatchesTypeSwitchTest extends TestCase
{
    public function test_accounts_list_splits_match_and_activation_only_accounts(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $withMatches = $this->makeAccount($tenant, ['matches_required' => 3]);
        $activationOnly = $this->makeAccount($tenant, ['matches_required' => 0]);

        $this->actingAsTenantUser($owner);

        Livewire::test(ListAccounts::class)
            ->assertSet('matchesType', 'matches')
            ->assertCanSeeTableRecords([$withMatches])
            ->assertCanNotSeeTableRecords([$activationOnly])
            ->set('matchesType', 'all')
            ->assertCanSeeTableRecords([$withMatches, $activationOnly])
            ->set('matchesType', 'matches')
            ->assertCanSeeTableRecords([$withMatches])
            ->assertCanNotSeeTableRecords([$activationOnly])
            ->set('matchesType', 'activation')
            ->assertCanSeeTableRecords([$activationOnly])
            ->assertCanNotSeeTableRecords([$withMatches]);
    }

    public function test_status_tab_badges_follow_the_selected_type(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $this->makeAccount($tenant, ['matches_required' => 3]);
        $this->makeAccount($tenant, ['matches_required' => 0]);
        $this->makeAccount($tenant, ['matches_required' => 0]);

        $this->actingAsTenantUser($owner);

        $page = Livewire::test(ListAccounts::class)->set('matchesType', 'activation')->instance();

        $this->assertSame(2, $page->getTabs()['all']->getBadge());
        $this->assertSame(['all' => 3, 'matches' => 1, 'activation' => 2], $page->getMatchesTypeCounts());
    }

    public function test_employee_queue_splits_by_type_with_counts(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $matchAssignment = $this->makeAssignment($tenant, $this->makeAccount($tenant, ['matches_required' => 3]), $employee, [
            'status' => AccountAssignment::STATUS_PENDING,
        ]);
        $plainAssignment = $this->makeAssignment($tenant, $this->makeAccount($tenant, ['matches_required' => 0]), $employee, [
            'status' => AccountAssignment::STATUS_PENDING,
        ]);

        $this->actingAsTenantUser($employee);

        // MyAccounts renders as a card grid, whose row keys don't match
        // what assertCanSeeTableRecords() looks for — check the records.
        $component = Livewire::test(MyAccounts::class)
            ->assertSee('تفعيل + 3 مباريات')
            ->assertSee('تفعيل فقط');
        $ids = fn () => $component->instance()->getTableRecords()->pluck('id')->sort()->values()->all();

        // No mixed "all" view for employees — opens on matches.
        $component->assertSet('matchesType', 'matches');
        $this->assertSame([$matchAssignment->id], $ids());
        $this->assertSame(['matches', 'activation'], $component->instance()->getMatchesTypeOptions());

        $component->set('matchesType', 'all')->assertSet('matchesType', 'matches');
        $this->assertSame([$matchAssignment->id], $ids());

        $component->set('matchesType', 'activation');
        $this->assertSame([$plainAssignment->id], $ids());

        $this->assertSame(['all' => 2, 'matches' => 1, 'activation' => 1], $component->instance()->getMatchesTypeCounts());
    }

    public function test_employee_with_only_activation_accounts_opens_on_that_tab(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $this->makeAssignment($tenant, $this->makeAccount($tenant, ['matches_required' => 0]), $employee, [
            'status' => AccountAssignment::STATUS_PENDING,
        ]);

        $this->actingAsTenantUser($employee);

        Livewire::test(MyAccounts::class)->assertSet('matchesType', 'activation');
    }

    public function test_unknown_type_falls_back_to_matches(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $account = $this->makeAccount($tenant, ['matches_required' => 3]);

        $this->actingAsTenantUser($owner);

        Livewire::test(ListAccounts::class)
            ->set('matchesType', 'bogus')
            ->assertSet('matchesType', 'matches')
            ->assertCanSeeTableRecords([$account]);
    }
}
