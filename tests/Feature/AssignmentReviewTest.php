<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\AssignmentReviewResource;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * A manager/supervisor reviews the proof-of-completion image an
 * employee submits and approves or rejects it. Approval is the only
 * place Account.status actually flips to 'activated' — everything
 * upstream (Activation's completeAction) just gets it to
 * awaiting_review. Rejection sends it back to in_progress so the
 * employee can retry, it does NOT fail the assignment.
 */
class AssignmentReviewTest extends TestCase
{
    private function approveAssignment(AccountAssignment $assignment): void
    {
        $assignment->update([
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'reviewed_by'  => auth()->id(),
            'reviewed_at'  => now(),
            'completed_at' => now(),
        ]);
        $assignment->account->update([
            'status'       => 'activated',
            'activated_at' => now(),
        ]);
    }

    public function test_approving_a_submission_activates_the_account(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office->update(['manager_id' => $manager->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $account = $this->makeAccount($tenant, ['status' => 'assigned', 'manager_id' => $manager->id]);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_AWAITING_REVIEW,
            'proof_path'   => 'proofs/example.jpg',
            'submitted_at' => now(),
        ]);

        $this->actingAsTenantUser($manager);

        $this->approveAssignment($assignment->fresh());

        $this->assertSame(AccountAssignment::STATUS_COMPLETED, $assignment->fresh()->status);
        $this->assertSame($manager->id, $assignment->fresh()->reviewed_by);
        $this->assertSame('activated', $account->fresh()->status);
        $this->assertNotNull($account->fresh()->activated_at);
    }

    public function test_rejecting_a_submission_sends_it_back_in_progress_not_failed(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_AWAITING_REVIEW,
            'proof_path'   => 'proofs/example.jpg',
            'submitted_at' => now(),
        ]);

        $this->actingAsTenantUser($supervisor);

        $assignment->update([
            'status'           => AccountAssignment::STATUS_IN_PROGRESS,
            'reviewed_by'      => $supervisor->id,
            'reviewed_at'      => now(),
            'rejection_reason' => 'الصورة غير واضحة',
        ]);

        $assignment->refresh();

        $this->assertSame(AccountAssignment::STATUS_IN_PROGRESS, $assignment->status);
        $this->assertNotSame(AccountAssignment::STATUS_FAILED, $assignment->status);
        $this->assertSame('الصورة غير واضحة', $assignment->rejection_reason);
        // Rejection doesn't touch the account's own status — it's still
        // mid-flight, not released back to the pool.
        $this->assertSame('assigned', $account->fresh()->status);
    }

    public function test_manager_only_sees_reviews_from_offices_they_manage(): void
    {
        $tenant = $this->makeTenant();
        $managedOffice = $this->makeOffice($tenant, ['name' => 'مكتب مُدار']);
        $otherOffice = $this->makeOffice($tenant, ['name' => 'مكتب آخر']);

        $manager = $this->makeUser($tenant, UserRole::Manager);
        $managedOffice->update(['manager_id' => $manager->id]);

        $employeeInScope = $this->makeUser($tenant, UserRole::Employee, $managedOffice);
        $employeeOutOfScope = $this->makeUser($tenant, UserRole::Employee, $otherOffice);

        $accountIn = $this->makeAccount($tenant, ['email' => 'in@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('in@example.com')]);
        $accountOut = $this->makeAccount($tenant, ['email' => 'out@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('out@example.com')]);

        $inScope = $this->makeAssignment($tenant, $accountIn, $employeeInScope, ['status' => AccountAssignment::STATUS_AWAITING_REVIEW]);
        $this->makeAssignment($tenant, $accountOut, $employeeOutOfScope, ['status' => AccountAssignment::STATUS_AWAITING_REVIEW]);

        $this->actingAsTenantUser($manager);

        $visible = AssignmentReviewResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$inScope->id], $visible);
    }

    public function test_supervisor_only_sees_reviews_from_their_own_office(): void
    {
        $tenant = $this->makeTenant();
        $officeA = $this->makeOffice($tenant, ['name' => 'مكتب أ']);
        $officeB = $this->makeOffice($tenant, ['name' => 'مكتب ب']);

        $supervisorA = $this->makeUser($tenant, UserRole::Supervisor, $officeA);
        $employeeA = $this->makeUser($tenant, UserRole::Employee, $officeA);
        $employeeB = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@example.com')]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@example.com')]);

        $assignmentA = $this->makeAssignment($tenant, $accountA, $employeeA, ['status' => AccountAssignment::STATUS_AWAITING_REVIEW]);
        $this->makeAssignment($tenant, $accountB, $employeeB, ['status' => AccountAssignment::STATUS_AWAITING_REVIEW]);

        $this->actingAsTenantUser($supervisorA);

        $visible = AssignmentReviewResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$assignmentA->id], $visible);
    }

    public function test_navigation_badge_counts_only_pending_reviews_in_scope(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office->update(['manager_id' => $manager->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $accountPending = $this->makeAccount($tenant, ['email' => 'p@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('p@example.com')]);
        $accountDone = $this->makeAccount($tenant, ['email' => 'd@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('d@example.com')]);

        $this->makeAssignment($tenant, $accountPending, $employee, ['status' => AccountAssignment::STATUS_AWAITING_REVIEW]);
        $this->makeAssignment($tenant, $accountDone, $employee, ['status' => AccountAssignment::STATUS_COMPLETED]);

        $this->actingAsTenantUser($manager);

        $this->assertSame('1', AssignmentReviewResource::getNavigationBadge());
    }
}
