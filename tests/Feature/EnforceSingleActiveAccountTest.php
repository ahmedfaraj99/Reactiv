<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\Activation;
use App\Filament\App\Pages\MyAccounts;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * Once an employee has pulled a 2FA code, EnforceSingleActiveAccount
 * must bounce them off every other App-panel page back to that one
 * assignment's activation screen — this is what makes the lock real
 * instead of just a UI suggestion an employee could route around by
 * typing a different URL.
 */
class EnforceSingleActiveAccountTest extends TestCase
{
    public function test_locked_employee_is_redirected_away_from_the_accounts_list(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $lockedAssignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'               => AccountAssignment::STATUS_IN_PROGRESS,
            'psn_totp_generations' => 1,
        ]);

        $this->actingAsTenantUser($employee);

        $response = $this->get(MyAccounts::getUrl());

        $response->assertRedirect(
            Activation::getUrl(['tenant' => $tenant->slug, 'assignment' => $lockedAssignment->id])
        );
    }

    public function test_locked_employee_can_still_reach_their_own_activation_page(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $lockedAssignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'               => AccountAssignment::STATUS_IN_PROGRESS,
            'psn_totp_generations' => 1,
        ]);

        $this->actingAsTenantUser($employee);

        $response = $this->get(Activation::getUrl(['tenant' => $tenant->slug, 'assignment' => $lockedAssignment->id]));

        $response->assertOk();
    }

    public function test_unlocked_employee_can_browse_freely(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $this->makeAssignment($tenant, $account, $employee, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS,
        ]);

        $this->actingAsTenantUser($employee);

        $response = $this->get(MyAccounts::getUrl());

        $response->assertOk();
    }
}
