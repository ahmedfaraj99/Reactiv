<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\ClientResource\Pages\ListClients;
use App\Models\AccountAssignment;
use App\Models\Client;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The clients table tells the owner how many of each client's accounts
 * were actually activated, next to how many were uploaded.
 */
class ClientActivatedCountTest extends TestCase
{
    public function test_each_client_shows_only_its_own_approved_activations(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $busy = Client::create(['tenant_id' => $tenant->id, 'name' => 'A']);
        $idle = Client::create(['tenant_id' => $tenant->id, 'name' => 'B']);

        $activated = $this->makeAccount($tenant, ['client_id' => $busy->id, 'status' => 'activated']);
        $this->makeAssignment($tenant, $activated, $employee, ['status' => AccountAssignment::STATUS_COMPLETED]);

        // Archiving changes the account's status but it was still activated.
        $archived = $this->makeAccount($tenant, ['client_id' => $busy->id, 'status' => 'retired']);
        $this->makeAssignment($tenant, $archived, $employee, ['status' => AccountAssignment::STATUS_COMPLETED]);

        // Proof submitted but not yet approved.
        $waiting = $this->makeAccount($tenant, ['client_id' => $busy->id, 'status' => 'assigned']);
        $this->makeAssignment($tenant, $waiting, $employee, ['status' => AccountAssignment::STATUS_AWAITING_REVIEW]);

        $this->makeAccount($tenant, ['client_id' => $idle->id]);

        $this->actingAsTenantUser($owner);

        Livewire::test(ListClients::class)
            ->assertTableColumnStateSet('activated_accounts_count', 2, $busy->getKey())
            ->assertTableColumnStateSet('accounts_count', 3, $busy->getKey())
            ->assertTableColumnStateSet('activated_accounts_count', 0, $idle->getKey())
            ->assertTableColumnStateSet('accounts_count', 1, $idle->getKey())
            ->sortTable('activated_accounts_count', 'desc')
            ->assertCanSeeTableRecords([$busy, $idle], inOrder: true);
    }
}
