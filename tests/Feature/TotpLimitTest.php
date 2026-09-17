<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * PSN and EA each allow 1 code pull before a supervisor has to
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

    public function test_ea_allows_exactly_one_generation_before_requiring_approval(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee);

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

    public function test_employee_may_have_several_started_activations_below_the_cap(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $accounts = [];
        $started = [];
        for ($i = 0; $i < AccountAssignment::MAX_CONCURRENT_ACTIVATIONS - 1; $i++) {
            $email = "u{$i}@example.com";
            $accounts[$i] = $this->makeAccount($tenant, [
                'email'             => $email,
                'email_fingerprint' => \App\Models\Account::fingerprint($email),
            ]);
            $started[$i] = $this->makeAssignment($tenant, $accounts[$i], $employee, [
                'status'               => AccountAssignment::STATUS_IN_PROGRESS,
                'psn_totp_generations' => 1,
            ]);
        }

        // A fresh, unstarted assignment is still openable — below the cap.
        $fresh = $this->makeAssignment(
            $tenant,
            $this->makeAccount($tenant, ['email' => 'fresh@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('fresh@example.com')]),
            $employee,
            ['status' => AccountAssignment::STATUS_PENDING]
        );

        $this->assertNull(AccountAssignment::lockedFor($employee->id, $fresh->id));
        foreach ($started as $s) {
            $this->assertNull(AccountAssignment::lockedFor($employee->id, $s->id));
        }
    }

    public function test_employee_at_activation_cap_cannot_open_a_new_assignment(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $started = [];
        for ($i = 0; $i < AccountAssignment::MAX_CONCURRENT_ACTIVATIONS; $i++) {
            $email = "u{$i}@example.com";
            $account = $this->makeAccount($tenant, [
                'email'             => $email,
                'email_fingerprint' => \App\Models\Account::fingerprint($email),
            ]);
            $started[$i] = $this->makeAssignment($tenant, $account, $employee, [
                'status'               => AccountAssignment::STATUS_IN_PROGRESS,
                'psn_totp_generations' => 1,
            ]);
        }

        $fresh = $this->makeAssignment(
            $tenant,
            $this->makeAccount($tenant, ['email' => 'fresh@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('fresh@example.com')]),
            $employee,
            ['status' => AccountAssignment::STATUS_PENDING]
        );

        // Opening the fresh assignment is blocked at the cap.
        $locked = AccountAssignment::lockedFor($employee->id, $fresh->id);
        $this->assertNotNull($locked);
        $this->assertSame($started[0]->id, $locked->id);

        // But any already-started assignment is still openable.
        foreach ($started as $s) {
            $this->assertNull(AccountAssignment::lockedFor($employee->id, $s->id));
        }
    }

    public function test_lockedfor_without_target_never_blocks_navigation(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        for ($i = 0; $i < AccountAssignment::MAX_CONCURRENT_ACTIVATIONS; $i++) {
            $email = "u{$i}@example.com";
            $account = $this->makeAccount($tenant, [
                'email'             => $email,
                'email_fingerprint' => \App\Models\Account::fingerprint($email),
            ]);
            $this->makeAssignment($tenant, $account, $employee, [
                'status'               => AccountAssignment::STATUS_IN_PROGRESS,
                'psn_totp_generations' => 1,
            ]);
        }

        // Non-activation pages pass null and are always allowed.
        $this->assertNull(AccountAssignment::lockedFor($employee->id));
    }
}
