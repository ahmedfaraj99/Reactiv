<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource\Pages\ListAccounts;
use App\Models\Account;
use Tests\TestCase;

class AccountListPageSmokeTest extends TestCase
{
    public function test_owner_can_load_the_accounts_list_with_the_manager_column(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $this->makeAccount($tenant, ['manager_id' => $manager->id]);

        $this->actingAsTenantUser($owner);

        $response = $this->get(ListAccounts::getUrl());

        $response->assertOk();
    }

    public function test_manager_can_load_the_accounts_list_scoped_to_their_batch(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $this->makeAccount($tenant, ['manager_id' => $manager->id]);

        $this->actingAsTenantUser($manager);

        $response = $this->get(ListAccounts::getUrl());

        $response->assertOk();
    }
}
