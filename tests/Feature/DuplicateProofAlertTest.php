<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\Activation;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Alert;
use Tests\TestCase;

/**
 * When an employee's proof upload hashes to the same value as an earlier
 * submission in the same tenant, a critical alert fires on the new
 * assignment pointing back at the original — the supervisor decides
 * whether to reject. Not a hard block: identical unrelated photos are
 * theoretically possible, and the reviewer is the human in the loop.
 *
 * Tests call flagIfDuplicate directly instead of driving through the
 * Filament FileUpload action — that pipeline's state-hydration for file
 * fields is a separate integration surface, and this suite is about the
 * duplicate-detection rule, not the upload plumbing.
 */
class DuplicateProofAlertTest extends TestCase
{
    private function activationFor(AccountAssignment $assignment): Activation
    {
        $page = new Activation();
        $page->assignment = $assignment;
        return $page;
    }

    public function test_a_hash_that_matches_an_earlier_submission_in_the_same_tenant_fires_a_critical_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => Account::fingerprint('a@example.com')]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => Account::fingerprint('b@example.com')]);

        $hash = str_repeat('a', 64);
        $earlier = $this->makeAssignment($tenant, $accountA, $employee, [
            'status'       => AccountAssignment::STATUS_AWAITING_REVIEW,
            'proof_path'   => 'proofs/earlier.jpg',
            'proof_hash'   => $hash,
            'submitted_at' => now()->subHour(),
        ]);
        $later = $this->makeAssignment($tenant, $accountB, $employee, [
            'status'     => AccountAssignment::STATUS_IN_PROGRESS,
            'proof_hash' => $hash,
        ]);

        $this->activationFor($later)->flagIfDuplicate($hash);

        $alert = Alert::where('type', Alert::TYPE_DUPLICATE_PROOF)->first();
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame($later->account_id, $alert->account_id);
        $this->assertSame($earlier->id, $alert->payload['original_assignment_id']);
        $this->assertSame($earlier->account_id, $alert->payload['original_account_id']);
    }

    public function test_no_alert_when_the_hash_is_unique_across_the_tenant(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS,
        ]);

        $this->activationFor($assignment)->flagIfDuplicate(str_repeat('b', 64));

        $this->assertSame(0, Alert::where('type', Alert::TYPE_DUPLICATE_PROOF)->count());
    }

    public function test_the_check_is_scoped_to_the_same_tenant_only(): void
    {
        $sharedHash = str_repeat('c', 64);

        $tenantOne = $this->makeTenant();
        $officeOne = $this->makeOffice($tenantOne);
        $employeeOne = $this->makeUser($tenantOne, UserRole::Employee, $officeOne);
        $accountOne = $this->makeAccount($tenantOne, ['email' => 'one@example.com', 'email_fingerprint' => Account::fingerprint('one@example.com')]);
        $this->makeAssignment($tenantOne, $accountOne, $employeeOne, [
            'status'       => AccountAssignment::STATUS_AWAITING_REVIEW,
            'proof_path'   => 'proofs/one.jpg',
            'proof_hash'   => $sharedHash,
            'submitted_at' => now()->subHour(),
        ]);

        $tenantTwo = $this->makeTenant();
        $officeTwo = $this->makeOffice($tenantTwo);
        $employeeTwo = $this->makeUser($tenantTwo, UserRole::Employee, $officeTwo);
        $accountTwo = $this->makeAccount($tenantTwo, ['email' => 'two@example.com', 'email_fingerprint' => Account::fingerprint('two@example.com')]);
        $assignmentTwo = $this->makeAssignment($tenantTwo, $accountTwo, $employeeTwo, [
            'status'     => AccountAssignment::STATUS_IN_PROGRESS,
            'proof_hash' => $sharedHash,
        ]);

        $this->activationFor($assignmentTwo)->flagIfDuplicate($sharedHash);

        // Two different tenants happening to have identical bytes is not a
        // cheating signal — it's just an unrelated coincidence. Alerts
        // must never cross the tenant boundary.
        $this->assertSame(0, Alert::where('type', Alert::TYPE_DUPLICATE_PROOF)->count());
    }
}
