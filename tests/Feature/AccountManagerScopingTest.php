<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\DistributeAccounts;
use App\Filament\App\Resources\AccountResource;
use App\Models\Account;
use Tests\TestCase;

/**
 * The owner uploads a batch of accounts for one specific manager (see
 * AccountResource's import action) — from that point on, only that
 * manager and the supervisors under them should see the batch. This
 * replaces the previous "every role sees every account in the tenant"
 * behavior, which the owner explicitly said doesn't match how the
 * business actually works: the owner only uploads, never distributes,
 * and a manager's batch is private to their own team.
 */
class AccountManagerScopingTest extends TestCase
{
    public function test_owner_sees_every_managers_batch(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);

        $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => Account::fingerprint('a@example.com'), 'manager_id' => $managerA->id]);
        $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => Account::fingerprint('b@example.com'), 'manager_id' => $managerB->id]);

        $this->actingAsTenantUser($owner);

        $this->assertSame(2, AccountResource::getEloquentQuery()->count());
    }

    public function test_manager_only_sees_their_own_batch(): void
    {
        $tenant = $this->makeTenant();
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => Account::fingerprint('a@example.com'), 'manager_id' => $managerA->id]);
        $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => Account::fingerprint('b@example.com'), 'manager_id' => $managerB->id]);

        $this->actingAsTenantUser($managerA);

        $visible = AccountResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$accountA->id], $visible);
    }

    public function test_supervisor_only_sees_their_managers_batch(): void
    {
        $tenant = $this->makeTenant();
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);

        $officeUnderA = $this->makeOffice($tenant, ['manager_id' => $managerA->id]);
        $supervisorUnderA = $this->makeUser($tenant, UserRole::Supervisor, $officeUnderA);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => Account::fingerprint('a@example.com'), 'manager_id' => $managerA->id]);
        $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => Account::fingerprint('b@example.com'), 'manager_id' => $managerB->id]);

        $this->actingAsTenantUser($supervisorUnderA);

        $visible = AccountResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$accountA->id], $visible);
    }

    public function test_owner_cannot_access_the_distribution_page(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $this->actingAsTenantUser($owner);

        $this->assertFalse(DistributeAccounts::canAccess());
    }

    public function test_distribution_pool_is_scoped_to_the_supervisors_manager(): void
    {
        $tenant = $this->makeTenant();
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);

        $officeUnderA = $this->makeOffice($tenant, ['manager_id' => $managerA->id]);
        $supervisorUnderA = $this->makeUser($tenant, UserRole::Supervisor, $officeUnderA);

        $this->makeAccount($tenant, ['email' => 'a1@example.com', 'email_fingerprint' => Account::fingerprint('a1@example.com'), 'manager_id' => $managerA->id, 'status' => 'available']);
        $this->makeAccount($tenant, ['email' => 'a2@example.com', 'email_fingerprint' => Account::fingerprint('a2@example.com'), 'manager_id' => $managerA->id, 'status' => 'available']);
        $this->makeAccount($tenant, ['email' => 'b1@example.com', 'email_fingerprint' => Account::fingerprint('b1@example.com'), 'manager_id' => $managerB->id, 'status' => 'available']);

        $this->actingAsTenantUser($supervisorUnderA);
        \Filament\Facades\Filament::setTenant($tenant);

        $page = new DistributeAccounts();

        $this->assertSame(2, $page->availableCount());
    }
}
