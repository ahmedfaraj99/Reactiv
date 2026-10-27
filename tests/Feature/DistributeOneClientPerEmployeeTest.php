<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\DistributeAccounts;
use App\Filament\App\Resources\AccountResource\Pages\ListAccounts;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Client;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mixing several customers' accounts on one employee's console risks a
 * chain lock, so an employee only ever holds one client's accounts at a
 * time. The manager picks the client from the stock and sees who is free.
 */
class DistributeOneClientPerEmployeeTest extends TestCase
{
    private function setUpPool(): array
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office = $this->makeOffice($tenant, ['manager_id' => $manager->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $clientA = Client::create(['tenant_id' => $tenant->id, 'name' => 'A']);
        $clientB = Client::create(['tenant_id' => $tenant->id, 'name' => 'B']);

        return [$tenant, $manager, $office, $employee, $clientA, $clientB];
    }

    private function account($tenant, $manager, Client $client, int $ageMinutes): Account
    {
        $a = $this->makeAccount($tenant, ['manager_id' => $manager->id, 'client_id' => $client->id, 'matches_required' => 0]);
        $a->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $a;
    }

    private function hold($tenant, $manager, $employee, Client $client, string $status = AccountAssignment::STATUS_PENDING): void
    {
        $held = $this->account($tenant, $manager, $client, 90);
        $held->update(['status' => 'assigned']);
        $this->makeAssignment($tenant, $held, $employee, ['status' => $status]);
    }

    /** @param array<int,array{employee_id:int,count:int}> $rows */
    private function distribute(?Client $client, array $rows)
    {
        $form = ['matches_type' => 'activation'];
        if ($client !== null) {
            $form['client'] = 'c'.$client->id;
        }

        return Livewire::test(DistributeAccounts::class)
            ->fillForm($form + [
                'employee_ids' => array_column($rows, 'employee_id'),
                'rows'         => $rows,
            ])
            ->callAction('distribute');
    }

    private function clientsOf(int $employeeId): array
    {
        return Account::whereIn('id', AccountAssignment::where('employee_id', $employeeId)->pluck('account_id'))
            ->pluck('client_id')->unique()->sort()->values()->all();
    }

    public function test_stock_is_grouped_by_client_oldest_waiting_first(): void
    {
        [$tenant, $manager, , , $clientA, $clientB] = $this->setUpPool();

        $this->account($tenant, $manager, $clientB, 50);
        $this->account($tenant, $manager, $clientA, 40);
        $this->account($tenant, $manager, $clientA, 30);

        $this->actingAsTenantUser($manager);
        $page = Livewire::test(DistributeAccounts::class)->instance();

        $stock = $page->clientStock('activation');
        $this->assertSame(['c'.$clientB->id, 'c'.$clientA->id], array_keys($stock));
        $this->assertSame(2, $stock['c'.$clientA->id]['count']);
        $this->assertSame('C01', $stock['c'.$clientA->id]['label']);
    }

    public function test_page_shows_stock_by_code_and_who_is_busy(): void
    {
        [$tenant, $manager, $office, $employee, $clientA, $clientB] = $this->setUpPool();
        $free = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->hold($tenant, $manager, $employee, $clientB);
        $this->account($tenant, $manager, $clientA, 50);

        $this->actingAsTenantUser($manager);

        Livewire::test(DistributeAccounts::class)
            ->fillForm(['matches_type' => 'activation'])
            ->assertOk()
            ->assertSee('C01 — 1 متاح')
            ->assertSee($employee->name.' — يعمل على C02: 1 تفعيل فقط')
            ->assertSee($free->name.' — حر')
            ->assertDontSee('>A<', false);
    }

    public function test_employee_holding_match_accounts_is_shown_as_such_on_the_activation_tab(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $held = $this->makeAccount($tenant, ['manager_id' => $manager->id, 'client_id' => $clientB->id, 'matches_required' => 3, 'status' => 'assigned']);
        $this->makeAssignment($tenant, $held, $employee);
        $this->account($tenant, $manager, $clientA, 50);

        $this->actingAsTenantUser($manager);

        Livewire::test(DistributeAccounts::class)
            ->fillForm(['matches_type' => 'activation'])
            ->assertSee($employee->name.' — يعمل على C02: 1 بمباريات')
            ->assertDontSee($employee->name.' — يعمل على C02: 1 تفعيل فقط');
    }

    public function test_the_oldest_waiting_client_is_picked_by_default(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        // Interleaved by upload time — a client-blind pick would mix them.
        $this->account($tenant, $manager, $clientA, 50);
        $this->account($tenant, $manager, $clientB, 40);
        $this->account($tenant, $manager, $clientA, 30);

        $this->actingAsTenantUser($manager);
        $this->distribute(null, [['employee_id' => $employee->id, 'count' => 2]]);

        $this->assertSame([$clientA->id], $this->clientsOf($employee->id));
        $this->assertSame(2, AccountAssignment::where('employee_id', $employee->id)->count());
    }

    public function test_distribution_takes_only_the_picked_client(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $this->account($tenant, $manager, $clientA, 50);
        $this->account($tenant, $manager, $clientB, 40);

        $this->actingAsTenantUser($manager);
        $this->distribute($clientB, [['employee_id' => $employee->id, 'count' => 1]]);

        $this->assertSame([$clientB->id], $this->clientsOf($employee->id));
    }

    public function test_refuses_more_than_the_clients_stock_instead_of_giving_fewer(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $this->account($tenant, $manager, $clientA, 50);
        $this->account($tenant, $manager, $clientA, 40);
        $this->account($tenant, $manager, $clientB, 30);

        $this->actingAsTenantUser($manager);
        $this->distribute($clientA, [['employee_id' => $employee->id, 'count' => 3]]);

        $this->assertSame(0, AccountAssignment::where('employee_id', $employee->id)->count());
    }

    public function test_employee_busy_with_another_client_is_refused(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $this->hold($tenant, $manager, $employee, $clientB);
        $a = $this->account($tenant, $manager, $clientA, 50);

        $this->actingAsTenantUser($manager);
        $this->distribute($clientA, [['employee_id' => $employee->id, 'count' => 1]]);

        $this->assertSame('available', $a->fresh()->status);
    }

    public function test_employee_busy_with_the_same_client_can_get_more(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $this->hold($tenant, $manager, $employee, $clientB, AccountAssignment::STATUS_IN_PROGRESS);
        $b = $this->account($tenant, $manager, $clientB, 50);

        $this->actingAsTenantUser($manager);
        $this->distribute($clientB, [['employee_id' => $employee->id, 'count' => 1]]);

        $this->assertSame('assigned', $b->fresh()->status);
    }

    public function test_already_mixed_employee_can_get_more_of_a_client_they_hold_only(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();
        $clientC = Client::create(['tenant_id' => $tenant->id, 'name' => 'C']);

        // Left holding A and B from before the rule existed.
        $this->hold($tenant, $manager, $employee, $clientA);
        $this->hold($tenant, $manager, $employee, $clientB);

        $b = $this->account($tenant, $manager, $clientB, 50);
        $c = $this->account($tenant, $manager, $clientC, 40);

        $this->actingAsTenantUser($manager);
        $this->distribute($clientB, [['employee_id' => $employee->id, 'count' => 1]]);
        $this->distribute($clientC, [['employee_id' => $employee->id, 'count' => 1]]);

        $this->assertSame('assigned', $b->fresh()->status);
        $this->assertSame('available', $c->fresh()->status);
    }

    public function test_finished_work_frees_the_employee_for_a_new_client(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $done = $this->account($tenant, $manager, $clientB, 90);
        $done->update(['status' => 'activated']);
        $this->makeAssignment($tenant, $done, $employee, ['status' => AccountAssignment::STATUS_COMPLETED]);

        $fresh = $this->account($tenant, $manager, $clientA, 50);

        $this->actingAsTenantUser($manager);
        $this->distribute($clientA, [['employee_id' => $employee->id, 'count' => 1]]);

        $this->assertSame('assigned', $fresh->fresh()->status);
    }

    private function held($tenant, $manager, $employee, Client $client, array $assignment): Account
    {
        $a = $this->account($tenant, $manager, $client, 90);
        $a->update(['status' => 'assigned']);
        $this->makeAssignment($tenant, $a, $employee, $assignment);

        return $a;
    }

    public function test_release_returns_unopened_and_abandoned_accounts_only(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $unopened = $this->held($tenant, $manager, $employee, $clientB, ['status' => AccountAssignment::STATUS_PENDING]);
        $stuck = $this->held($tenant, $manager, $employee, $clientB, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS, 'credentials_revealed_at' => now()->subHours(49),
        ]);
        $working = $this->held($tenant, $manager, $employee, $clientB, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS, 'credentials_revealed_at' => now()->subHours(47),
        ]);
        $sentBack = $this->held($tenant, $manager, $employee, $clientB, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS, 'credentials_revealed_at' => now()->subDays(5),
            'rejection_reason' => 'صورة غير واضحة',
        ]);
        $review = $this->held($tenant, $manager, $employee, $clientB, [
            'status' => AccountAssignment::STATUS_AWAITING_REVIEW, 'credentials_revealed_at' => now()->subDays(5),
        ]);

        $this->actingAsTenantUser($manager);

        Livewire::test(DistributeAccounts::class)
            ->callAction('releaseEmployee', arguments: ['employee' => $employee->id]);

        $this->assertSame('available', $unopened->fresh()->status);
        $this->assertSame('available', $stuck->fresh()->status);
        $this->assertNull($unopened->fresh()->assignment);
        $this->assertSame($manager->id, $stuck->fresh()->manager_id);

        foreach ([$working, $sentBack, $review] as $kept) {
            $this->assertSame('assigned', $kept->fresh()->status);
            $this->assertSame($employee->id, $kept->fresh()->assignment->employee_id);
        }

        $this->assertSame(2, \App\Models\AccountAdminLog::where('action', \App\Models\AccountAdminLog::ACTION_RELEASED_TO_STOCK)->count());
    }

    public function test_released_employee_can_take_a_new_client(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $this->held($tenant, $manager, $employee, $clientB, ['status' => AccountAssignment::STATUS_PENDING]);
        $a = $this->account($tenant, $manager, $clientA, 200);

        $this->actingAsTenantUser($manager);

        Livewire::test(DistributeAccounts::class)
            ->callAction('releaseAll');

        $this->distribute($clientA, [['employee_id' => $employee->id, 'count' => 1]]);

        $this->assertSame([$clientA->id], $this->clientsOf($employee->id));
        $this->assertSame('assigned', $a->fresh()->status);
    }

    public function test_page_lists_blocked_employees_with_what_release_would_return(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $this->held($tenant, $manager, $employee, $clientB, ['status' => AccountAssignment::STATUS_PENDING]);
        $this->account($tenant, $manager, $clientA, 200);

        $this->actingAsTenantUser($manager);

        Livewire::test(DistributeAccounts::class)
            ->fillForm(['matches_type' => 'activation', 'client' => 'c'.$clientA->id])
            ->assertSee('موظفون لا يمكنهم استلام هذا العميل الآن (1)')
            ->assertSee('قابل للتحرير: 1 غير مفتوح');
    }

    public function test_page_shows_how_long_stuck_accounts_have_been_open(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $this->held($tenant, $manager, $employee, $clientB, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS, 'credentials_revealed_at' => now()->subDays(12),
        ]);
        $this->account($tenant, $manager, $clientA, 200);

        $this->actingAsTenantUser($manager);
        app()->setLocale('ar');

        Livewire::test(DistributeAccounts::class)
            ->fillForm(['matches_type' => 'activation', 'client' => 'c'.$clientA->id])
            ->assertSee('قابل للتحرير: 1 عالق منذ')
            ->mountAction('releaseAll')
            ->assertActionMounted('releaseAll')
            ->assertSee('0 غير مفتوح، و1 عالق');
    }

    public function test_bulk_reassign_allows_a_client_the_mixed_employee_already_holds(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $this->hold($tenant, $manager, $employee, $clientA);
        $this->hold($tenant, $manager, $employee, $clientB);
        $a = $this->account($tenant, $manager, $clientA, 50);

        $this->actingAsTenantUser($manager);

        Livewire::test(ListAccounts::class)
            ->set('matchesType', 'activation')
            ->callTableBulkAction('reassign_bulk', [$a], data: ['mode' => 'single', 'employee_id' => $employee->id]);

        $this->assertSame('assigned', $a->fresh()->status);
    }

    public function test_bulk_reassign_refuses_mixed_clients(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $a = $this->account($tenant, $manager, $clientA, 50);
        $b = $this->account($tenant, $manager, $clientB, 40);

        $this->actingAsTenantUser($manager);

        Livewire::test(ListAccounts::class)
            ->set('matchesType', 'activation')
            ->callTableBulkAction('reassign_bulk', [$a, $b], data: ['mode' => 'single', 'employee_id' => $employee->id]);

        $this->assertSame(0, AccountAssignment::where('employee_id', $employee->id)->count());
    }

    public function test_bulk_reassign_refuses_a_second_client_for_a_busy_employee(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $this->hold($tenant, $manager, $employee, $clientB, AccountAssignment::STATUS_IN_PROGRESS);
        $a = $this->account($tenant, $manager, $clientA, 50);

        $this->actingAsTenantUser($manager);

        Livewire::test(ListAccounts::class)
            ->set('matchesType', 'activation')
            ->callTableBulkAction('reassign_bulk', [$a], data: ['mode' => 'single', 'employee_id' => $employee->id]);

        $this->assertSame('available', $a->fresh()->status);
    }
}
