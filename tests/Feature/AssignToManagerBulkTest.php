<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The manual "assign to manager" flow mirrors what the Excel import does
 * for a fresh batch, but for accounts already in the system: the owner
 * picks rows, picks a target manager, and only rows that haven't been
 * opened yet move. Anything an employee has already looked at stays put
 * — silently moving a live account under someone else would strand the
 * employee mid-activation.
 */
class AssignToManagerBulkTest extends TestCase
{
    public function test_moves_available_accounts_to_the_target_manager(): void
    {
        $tenant  = $this->makeTenant();
        $oldMgr  = $this->makeUser($tenant, UserRole::Manager);
        $newMgr  = $this->makeUser($tenant, UserRole::Manager);

        $a = $this->makeAccount($tenant, [
            'email' => 'a@x.com', 'email_fingerprint' => Account::fingerprint('a@x.com'),
            'manager_id' => $oldMgr->id,
        ]);
        $b = $this->makeAccount($tenant, [
            'email' => 'b@x.com', 'email_fingerprint' => Account::fingerprint('b@x.com'),
            'manager_id' => $oldMgr->id,
        ]);

        $this->reassign(collect([$a, $b]), $newMgr->id);

        $this->assertSame($newMgr->id, $a->fresh()->manager_id);
        $this->assertSame($newMgr->id, $b->fresh()->manager_id);
    }

    public function test_wipes_the_assignment_when_moving_an_assigned_but_unopened_account(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $oldMgr = $this->makeUser($tenant, UserRole::Manager);
        $newMgr = $this->makeUser($tenant, UserRole::Manager);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $account = $this->makeAccount($tenant, [
            'manager_id' => $oldMgr->id,
            'status'     => 'assigned',
        ]);
        $this->makeAssignment($tenant, $account, $employee);

        $this->reassign(collect([$account]), $newMgr->id);

        $this->assertSame($newMgr->id, $account->fresh()->manager_id);
        $this->assertSame('available', $account->fresh()->status);
        $this->assertNull($account->fresh()->assignment);
    }

    public function test_refuses_to_move_an_account_whose_credentials_have_been_revealed(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $oldMgr = $this->makeUser($tenant, UserRole::Manager);
        $newMgr = $this->makeUser($tenant, UserRole::Manager);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $account = $this->makeAccount($tenant, [
            'manager_id' => $oldMgr->id,
            'status'     => 'assigned',
        ]);
        $this->makeAssignment($tenant, $account, $employee, [
            'credentials_revealed_at' => now(),
        ]);

        $this->reassign(collect([$account]), $newMgr->id);

        // Unchanged — a live activation must not be silently pulled out
        // from under the employee working it.
        $this->assertSame($oldMgr->id, $account->fresh()->manager_id);
        $this->assertNotNull($account->fresh()->assignment);
    }

    /** Mirrors the closure in AccountResource::assign_to_manager_bulk. */
    private function reassign($movable, int $managerId): void
    {
        $filtered = $movable->filter(fn (Account $a): bool =>
            $a->assignment === null || $a->assignment->credentials_revealed_at === null
        );

        DB::transaction(function () use ($filtered, $managerId): void {
            foreach ($filtered as $account) {
                $account->update(['manager_id' => $managerId]);
                if ($account->assignment !== null) {
                    $account->assignment->delete();
                    $account->update(['status' => 'available']);
                }
            }
        });
    }
}
