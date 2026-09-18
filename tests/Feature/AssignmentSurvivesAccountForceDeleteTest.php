<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * When the owner force-deletes an account, the employee's historical
 * assignments MUST survive (with account_id=NULL) so their completed
 * count on the dashboard doesn't silently shrink. This test is the
 * regression guard for the FK cascade → nullOnDelete migration.
 */
class AssignmentSurvivesAccountForceDeleteTest extends TestCase
{
    public function test_force_deleting_an_account_keeps_the_completed_assignment_row(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        // Owner escape hatch — permanent delete, bypasses soft-delete.
        $account->forceDelete();

        $surviving = AccountAssignment::query()->find($assignment->id);

        $this->assertNotNull($surviving, 'The completed assignment must survive force-delete of its account.');
        $this->assertNull($surviving->account_id, 'account_id should be nulled by the FK, not cascade-deleted.');
        $this->assertSame(AccountAssignment::STATUS_COMPLETED, $surviving->status);
        $this->assertSame($employee->id, $surviving->employee_id);
    }

    public function test_completed_count_for_employee_is_unaffected_by_force_delete(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        // Three completed activations, then force-delete two of the
        // underlying accounts — the count the stats widget queries
        // (status = completed, employee_id = X) must still be 3.
        for ($i = 0; $i < 3; $i++) {
            $account = $this->makeAccount($tenant);
            $this->makeAssignment($tenant, $account, $employee, [
                'status'       => AccountAssignment::STATUS_COMPLETED,
                'completed_at' => now()->subDays($i),
            ]);
            if ($i < 2) {
                $account->forceDelete();
            }
        }

        $count = AccountAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('status', AccountAssignment::STATUS_COMPLETED)
            ->count();

        $this->assertSame(3, $count);
    }
}
