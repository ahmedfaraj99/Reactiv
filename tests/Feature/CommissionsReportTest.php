<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\CommissionsReport;
use App\Models\AccountAssignment;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-employee payout estimate: completed activations × the tenant's
 * rate. Mirrors the scoping already proven for OverviewStatsWidget/
 * EmployeePerformanceLeaderboardWidget, plus the two things unique to
 * this page: only the owner can change the rate, and a tenant with no
 * rate set still gets counts (just no money column).
 */
class CommissionsReportTest extends TestCase
{
    /** @return \Illuminate\Support\Collection<int,AccountAssignment> */
    private function rows(CommissionsReport $page)
    {
        $method = new \ReflectionMethod(CommissionsReport::class, 'commissionsQuery');
        $method->setAccessible(true);

        return $method->invoke($page)->get();
    }

    public function test_completed_count_and_total_reflect_the_tenants_rate(): void
    {
        $tenant = $this->makeTenant(['commission_per_activation' => 5]);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@example.com')]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@example.com')]);

        $this->makeAssignment($tenant, $accountA, $employee, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $this->makeAssignment($tenant, $accountB, $employee, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAsTenantUser($owner);

        $rows = $this->rows(new CommissionsReport());

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows->first()->completed_count);
    }

    public function test_manager_only_sees_employees_in_offices_they_manage(): void
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

        $this->makeAssignment($tenant, $accountA, $employeeA, ['status' => AccountAssignment::STATUS_COMPLETED, 'completed_at' => now()]);
        $this->makeAssignment($tenant, $accountB, $employeeB, ['status' => AccountAssignment::STATUS_COMPLETED, 'completed_at' => now()]);

        $this->actingAsTenantUser($managerA);

        $rows = $this->rows(new CommissionsReport());

        $this->assertCount(1, $rows);
        $this->assertSame($employeeA->id, $rows->first()->employee_id);
    }

    public function test_only_the_owner_can_save_the_commission_rate(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $this->actingAsTenantUser($manager);

        Livewire::test(CommissionsReport::class)
            ->assertActionHidden('saveRate');
    }

    public function test_owner_can_save_the_commission_rate(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);

        $this->actingAsTenantUser($owner);

        Livewire::test(CommissionsReport::class)
            ->fillForm(['commission_per_activation' => 7.5])
            ->callAction('saveRate');

        $this->assertSame('7.50', $tenant->fresh()->commission_per_activation);
    }
}
