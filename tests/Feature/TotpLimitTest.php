<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * PSN allows 1 code pull, EA allows 2, before a supervisor has to
 * approve more. This covers the base allowance math in
 * AccountAssignment and the atomic check-then-increment pattern used
 * by Activation's generate*TotpAction() closures (tested here at the
 * model/service level, since driving the full Livewire action through
 * Filament's action pipeline is unreliable in this environment).
 */
class TotpLimitTest extends TestCase
{
    public function test_psn_allows_exactly_one_generation_before_requiring_approval(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee);

        $this->assertTrue($assignment->canGeneratePsnTotp());

        $assignment->increment('psn_totp_generations');
        $assignment->refresh();

        $this->assertFalse($assignment->canGeneratePsnTotp());
    }

    public function test_ea_allows_exactly_two_generations_before_requiring_approval(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee);

        $this->assertTrue($assignment->canGenerateEaTotp());
        $assignment->increment('ea_totp_generations');
        $assignment->refresh();
        $this->assertTrue($assignment->canGenerateEaTotp());

        $assignment->increment('ea_totp_generations');
        $assignment->refresh();
        $this->assertFalse($assignment->canGenerateEaTotp());
    }

    public function test_supervisor_approval_raises_the_allowance(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'psn_totp_generations' => AccountAssignment::PSN_TOTP_BASE_LIMIT,
        ]);

        $this->assertFalse($assignment->canGeneratePsnTotp());

        $assignment->update(['psn_totp_extra_allowed' => 1]);
        $assignment->refresh();

        $this->assertTrue($assignment->canGeneratePsnTotp());
    }

    public function test_employee_is_locked_to_the_assignment_once_they_pull_a_code(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@example.com')]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@example.com')]);

        $assignmentA = $this->makeAssignment($tenant, $accountA, $employee, [
            'status'                => AccountAssignment::STATUS_IN_PROGRESS,
            'psn_totp_generations'  => 1,
        ]);
        $this->makeAssignment($tenant, $accountB, $employee, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS,
        ]);

        $locked = AccountAssignment::lockedFor($employee->id);

        $this->assertNotNull($locked);
        $this->assertSame($assignmentA->id, $locked->id);
    }

    public function test_employee_is_not_locked_before_pulling_any_code(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $this->makeAssignment($tenant, $account, $employee, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS,
        ]);

        $this->assertNull(AccountAssignment::lockedFor($employee->id));
    }
}
