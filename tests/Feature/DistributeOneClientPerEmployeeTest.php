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
 * time — even if that means receiving fewer than asked.
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

    private function distribute(array $rows): void
    {
        Livewire::test(DistributeAccounts::class)
            ->fillForm([
                'matches_type' => 'activation',
                'employee_ids' => array_column($rows, 'employee_id'),
                'rows'         => $rows,
            ])
            ->callAction('distribute');
    }

    private function clientsOf(int $employeeId): array
    {
        return Account::whereIn('id', AccountAssignment::where('employee_id', $employeeId)->pluck('account_id'))
            ->pluck('client_id')->unique()->values()->all();
    }

    public function test_employee_gets_only_the_oldest_client_even_if_short(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        // Interleaved by upload time — oldest-first alone would mix them.
        $this->account($tenant, $manager, $clientA, 50);
        $this->account($tenant, $manager, $clientB, 40);
        $this->account($tenant, $manager, $clientA, 30);
        $this->account($tenant, $manager, $clientB, 20);

        $this->actingAsTenantUser($manager);
        $this->distribute([['employee_id' => $employee->id, 'count' => 3]]);

        $this->assertSame(2, AccountAssignment::where('employee_id', $employee->id)->count());
        $this->assertSame([$clientA->id], $this->clientsOf($employee->id));
    }

    public function test_second_employee_moves_on_to_the_next_client(): void
    {
        [$tenant, $manager, $office, $employee, $clientA, $clientB] = $this->setUpPool();
        $second = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->account($tenant, $manager, $clientA, 50);
        $this->account($tenant, $manager, $clientB, 40);
        $this->account($tenant, $manager, $clientB, 30);

        $this->actingAsTenantUser($manager);
        $this->distribute([
            ['employee_id' => $employee->id, 'count' => 1],
            ['employee_id' => $second->id, 'count' => 2],
        ]);

        $this->assertSame([$clientA->id], $this->clientsOf($employee->id));
        $this->assertSame([$clientB->id], $this->clientsOf($second->id));
        $this->assertSame(2, AccountAssignment::where('employee_id', $second->id)->count());
    }

    public function test_employee_with_open_work_stays_on_that_client(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $held = $this->account($tenant, $manager, $clientB, 90);
        $held->update(['status' => 'assigned']);
        $this->makeAssignment($tenant, $held, $employee, ['status' => AccountAssignment::STATUS_PENDING]);

        $this->account($tenant, $manager, $clientA, 50);
        $this->account($tenant, $manager, $clientB, 40);

        $this->actingAsTenantUser($manager);
        $this->distribute([['employee_id' => $employee->id, 'count' => 1]]);

        $this->assertSame([$clientB->id], $this->clientsOf($employee->id));
        $this->assertSame(2, AccountAssignment::where('employee_id', $employee->id)->count());
    }

    public function test_already_mixed_employee_still_gets_more_of_a_client_they_hold(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();
        $clientC = Client::create(['tenant_id' => $tenant->id, 'name' => 'C']);

        // Left holding A and B from before the rule existed.
        foreach ([$clientA, $clientB] as $client) {
            $held = $this->account($tenant, $manager, $client, 90);
            $held->update(['status' => 'assigned']);
            $this->makeAssignment($tenant, $held, $employee, ['status' => AccountAssignment::STATUS_PENDING]);
        }

        // C is oldest, but it would be a third client on the console.
        $c = $this->account($tenant, $manager, $clientC, 60);
        $b1 = $this->account($tenant, $manager, $clientB, 50);
        $a1 = $this->account($tenant, $manager, $clientA, 40);
        $b2 = $this->account($tenant, $manager, $clientB, 30);

        $this->actingAsTenantUser($manager);
        $this->distribute([['employee_id' => $employee->id, 'count' => 3]]);

        // Oldest among A/B is B — take only B this round, never C.
        $this->assertSame('assigned', $b1->fresh()->status);
        $this->assertSame('assigned', $b2->fresh()->status);
        $this->assertSame('available', $a1->fresh()->status);
        $this->assertSame('available', $c->fresh()->status);
    }

    public function test_bulk_reassign_allows_a_client_the_mixed_employee_already_holds(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        foreach ([$clientA, $clientB] as $client) {
            $held = $this->account($tenant, $manager, $client, 90);
            $held->update(['status' => 'assigned']);
            $this->makeAssignment($tenant, $held, $employee, ['status' => AccountAssignment::STATUS_PENDING]);
        }

        $a = $this->account($tenant, $manager, $clientA, 50);

        $this->actingAsTenantUser($manager);

        Livewire::test(ListAccounts::class)
            ->set('matchesType', 'activation')
            ->callTableBulkAction('reassign_bulk', [$a], data: ['mode' => 'single', 'employee_id' => $employee->id]);

        $this->assertSame('assigned', $a->fresh()->status);
    }

    public function test_finished_work_frees_the_employee_for_a_new_client(): void
    {
        [$tenant, $manager, , $employee, $clientA, $clientB] = $this->setUpPool();

        $done = $this->account($tenant, $manager, $clientB, 90);
        $done->update(['status' => 'activated']);
        $this->makeAssignment($tenant, $done, $employee, ['status' => AccountAssignment::STATUS_COMPLETED]);

        $fresh = $this->account($tenant, $manager, $clientA, 50);

        $this->actingAsTenantUser($manager);
        $this->distribute([['employee_id' => $employee->id, 'count' => 1]]);

        $this->assertSame('assigned', $fresh->fresh()->status);
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

        $held = $this->account($tenant, $manager, $clientB, 90);
        $held->update(['status' => 'assigned']);
        $this->makeAssignment($tenant, $held, $employee, ['status' => AccountAssignment::STATUS_IN_PROGRESS]);

        $a = $this->account($tenant, $manager, $clientA, 50);

        $this->actingAsTenantUser($manager);

        Livewire::test(ListAccounts::class)
            ->set('matchesType', 'activation')
            ->callTableBulkAction('reassign_bulk', [$a], data: ['mode' => 'single', 'employee_id' => $employee->id]);

        $this->assertSame('available', $a->fresh()->status);
    }
}
