<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\Activation;
use App\Filament\App\Pages\MyAccounts;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * Employees may have up to MAX_CONCURRENT_ACTIVATIONS started at once
 * and switch between them from "حساباتي". Once they've hit the cap,
 * EnforceSingleActiveAccount must bounce any attempt to open a NEW
 * activation back to one of the already-started ones — this is what
 * makes the cap real instead of just a UI suggestion an employee could
 * route around by typing a different URL.
 */
class EnforceSingleActiveAccountTest extends TestCase
{
    /**
     * Create the given number of started activations for the employee.
     *
     * @return array<int, AccountAssignment>
     */
    private function startedAssignments($tenant, $employee, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $email = "started{$i}@example.com";
            $account = $this->makeAccount($tenant, [
                'status'            => 'assigned',
                'email'             => $email,
                'email_fingerprint' => \App\Models\Account::fingerprint($email),
            ]);
            $out[] = $this->makeAssignment($tenant, $account, $employee, [
                'status'               => AccountAssignment::STATUS_IN_PROGRESS,
                'psn_totp_generations' => 1,
            ]);
        }
        return $out;
    }

    public function test_employee_at_cap_is_redirected_away_from_a_new_activation(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $started = $this->startedAssignments($tenant, $employee, AccountAssignment::MAX_CONCURRENT_ACTIVATIONS);

        $freshAccount = $this->makeAccount($tenant, [
            'status'            => 'assigned',
            'email'             => 'fresh@example.com',
            'email_fingerprint' => \App\Models\Account::fingerprint('fresh@example.com'),
        ]);
        $freshAssignment = $this->makeAssignment($tenant, $freshAccount, $employee, [
            'status' => AccountAssignment::STATUS_PENDING,
        ]);

        $this->actingAsTenantUser($employee);

        $response = $this->get(Activation::getUrl(['tenant' => $tenant->slug, 'assignment' => $freshAssignment->id]));

        $response->assertRedirect(
            Activation::getUrl(['tenant' => $tenant->slug, 'assignment' => $started[0]->id])
        );
    }

    public function test_employee_can_reach_any_of_their_started_activations(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $started = $this->startedAssignments($tenant, $employee, AccountAssignment::MAX_CONCURRENT_ACTIVATIONS);

        $this->actingAsTenantUser($employee);

        foreach ($started as $assignment) {
            $response = $this->get(Activation::getUrl(['tenant' => $tenant->slug, 'assignment' => $assignment->id]));
            $response->assertOk();
        }
    }

    public function test_employee_at_cap_can_still_reach_my_accounts_to_switch(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->startedAssignments($tenant, $employee, AccountAssignment::MAX_CONCURRENT_ACTIVATIONS);

        $this->actingAsTenantUser($employee);

        $this->get(MyAccounts::getUrl())->assertOk();
    }

    public function test_employee_below_cap_can_open_a_new_activation(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->startedAssignments($tenant, $employee, AccountAssignment::MAX_CONCURRENT_ACTIVATIONS - 1);

        $freshAccount = $this->makeAccount($tenant, [
            'status'            => 'assigned',
            'email'             => 'fresh@example.com',
            'email_fingerprint' => \App\Models\Account::fingerprint('fresh@example.com'),
        ]);
        $freshAssignment = $this->makeAssignment($tenant, $freshAccount, $employee, [
            'status' => AccountAssignment::STATUS_PENDING,
        ]);

        $this->actingAsTenantUser($employee);

        $this->get(Activation::getUrl(['tenant' => $tenant->slug, 'assignment' => $freshAssignment->id]))
            ->assertOk();
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

        $this->get(MyAccounts::getUrl())->assertOk();
    }
}
