<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Widgets\EmployeePerformanceLeaderboardWidget;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * Mirrors OverviewStatsWidgetTest's approach: a manager must never see
 * another manager's employees on the leaderboard, and the aggregate
 * counts/timing must match what was actually recorded.
 */
class EmployeePerformanceLeaderboardWidgetTest extends TestCase
{
    /** @return \Illuminate\Support\Collection<int,AccountAssignment> */
    private function rows(EmployeePerformanceLeaderboardWidget $widget)
    {
        $method = new \ReflectionMethod(EmployeePerformanceLeaderboardWidget::class, 'leaderboardQuery');
        $method->setAccessible(true);

        return $method->invoke($widget)->get();
    }

    public function test_manager_leaderboard_excludes_another_managers_employees(): void
    {
        $tenant = $this->makeTenant();
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);
        $officeA = $this->makeOffice($tenant, ['manager_id' => $managerA->id]);
        $officeB = $this->makeOffice($tenant, ['manager_id' => $managerB->id]);
        $employeeA = $this->makeUser($tenant, UserRole::Employee, $officeA);
        $employeeB = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@example.com')]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@example.com')]);

        $this->makeAssignment($tenant, $accountA, $employeeA, ['status' => AccountAssignment::STATUS_COMPLETED]);
        $this->makeAssignment($tenant, $accountB, $employeeB, ['status' => AccountAssignment::STATUS_COMPLETED]);

        $this->actingAsTenantUser($managerA);

        $rows = $this->rows(new EmployeePerformanceLeaderboardWidget());

        $this->assertCount(1, $rows);
        $this->assertSame($employeeA->id, $rows->first()->employee_id);
    }

    public function test_completed_and_failed_counts_and_average_completion_time_are_correct(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@example.com')]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@example.com')]);
        $accountC = $this->makeAccount($tenant, ['email' => 'c@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('c@example.com')]);

        // Employee work time = credentials_revealed_at → submitted_at.
        // completed_at is the supervisor-approved timestamp and only
        // relevant to the account's status transition, not the metric.
        $started = now()->subHour();
        $this->makeAssignment($tenant, $accountA, $employee, [
            'status'                  => AccountAssignment::STATUS_COMPLETED,
            'started_at'              => $started,
            'credentials_revealed_at' => $started,
            'submitted_at'            => $started->copy()->addMinutes(10),
            'completed_at'            => $started->copy()->addMinutes(30),
        ]);
        $this->makeAssignment($tenant, $accountB, $employee, [
            'status'                  => AccountAssignment::STATUS_COMPLETED,
            'started_at'              => $started,
            'credentials_revealed_at' => $started,
            'submitted_at'            => $started->copy()->addMinutes(20),
            'completed_at'            => $started->copy()->addMinutes(40),
        ]);
        $this->makeAssignment($tenant, $accountC, $employee, [
            'status' => AccountAssignment::STATUS_FAILED,
        ]);

        $this->actingAsTenantUser($owner);

        $rows = $this->rows(new EmployeePerformanceLeaderboardWidget());

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(2, (int) $row->completed_count);
        $this->assertSame(1, (int) $row->failed_count);
        $this->assertEqualsWithDelta(15.0, (float) $row->avg_minutes, 0.1);
    }

    public function test_pending_assignments_do_not_appear_on_the_leaderboard(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $this->makeAssignment($tenant, $account, $employee, ['status' => AccountAssignment::STATUS_PENDING]);

        $this->actingAsTenantUser($owner);

        $rows = $this->rows(new EmployeePerformanceLeaderboardWidget());

        $this->assertCount(0, $rows);
    }
}
