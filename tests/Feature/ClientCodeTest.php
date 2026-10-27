<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\DistributeAccounts;
use App\Models\Client;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Managers and supervisors tell clients apart by a neutral code — the
 * client's real name is the owner's business only.
 */
class ClientCodeTest extends TestCase
{
    public function test_clients_get_sequential_codes_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();

        $a = Client::create(['tenant_id' => $tenant->id, 'name' => 'A']);
        $b = Client::create(['tenant_id' => $tenant->id, 'name' => 'B']);
        $x = Client::create(['tenant_id' => $other->id, 'name' => 'X']);

        $this->assertSame('C01', $a->code);
        $this->assertSame('C02', $b->code);
        $this->assertSame('C01', $x->code);
    }

    public function test_a_deleted_clients_code_is_never_reused(): void
    {
        $tenant = $this->makeTenant();

        Client::create(['tenant_id' => $tenant->id, 'name' => 'A']);
        Client::create(['tenant_id' => $tenant->id, 'name' => 'B'])->delete();

        $this->assertSame('C03', Client::create(['tenant_id' => $tenant->id, 'name' => 'C'])->code);
    }

    public function test_manager_sees_only_the_code_and_owner_sees_the_name_too(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'اسم سري']);

        $this->actingAsTenantUser($manager);
        $this->assertSame('C01', Client::labelFor($client->id));

        $this->actingAsTenantUser($owner);
        $this->assertSame('C01 — اسم سري', Client::labelFor($client->id));
    }

    public function test_distribution_notification_never_shows_the_client_name_to_a_manager(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office = $this->makeOffice($tenant, ['manager_id' => $manager->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'اسم سري']);
        $this->makeAccount($tenant, ['manager_id' => $manager->id, 'client_id' => $client->id, 'matches_required' => 0]);

        $this->actingAsTenantUser($manager);

        Livewire::test(DistributeAccounts::class)
            ->fillForm([
                'matches_type' => 'activation',
                'employee_ids' => [$employee->id],
                'rows'         => [['employee_id' => $employee->id, 'count' => 1]],
            ])
            ->callAction('distribute');

        $notifications = new \Filament\Notifications\Livewire\Notifications;
        $notifications->mount();
        $bodies = $notifications->notifications->map(fn ($n): string => (string) $n->getBody())->implode(' ');
        $this->assertStringContainsString('C01', $bodies);
        $this->assertStringNotContainsString('اسم سري', $bodies);
    }
}
