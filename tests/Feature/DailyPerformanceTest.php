<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\DailyPerformance;
use App\Models\AccountAssignment;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Daily quota monitoring: per-employee target (override or tenant
 * default), counts credited by submission day, reach limited to the
 * viewer's employees, and only owner/manager may change targets.
 */
class DailyPerformanceTest extends TestCase
{
    private function complete(Tenant $tenant, User $employee, array $attributes = []): AccountAssignment
    {
        return $this->makeAssignment($tenant, $this->makeAccount($tenant), $employee, array_merge([
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'submitted_at' => now(),
            'completed_at' => now(),
        ], $attributes));
    }

    /** @return \Illuminate\Support\Collection<int,User> keyed by id */
    private function rows(DailyPerformance $page)
    {
        return $page->rows()->keyBy('id');
    }

    private function page(?string $date = null): DailyPerformance
    {
        $page = new DailyPerformance();
        $page->date = $date ?? now()->toDateString();

        return $page;
    }

    public function test_counts_against_default_target_or_personal_override(): void
    {
        $tenant = $this->makeTenant(['default_daily_target' => 3]);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $onDefault = $this->makeUser($tenant, UserRole::Employee, $office);
        $withOverride = $this->makeUser($tenant, UserRole::Employee, $office, ['daily_target' => 1]);
        $idle = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->complete($tenant, $onDefault);
        $this->complete($tenant, $onDefault);
        $this->complete($tenant, $withOverride);

        $this->actingAsTenantUser($owner);
        $rows = $this->rows($this->page());

        $this->assertSame(3, (int) $rows[$onDefault->id]->effective_target);
        $this->assertSame(2, (int) $rows[$onDefault->id]->done_count);
        $this->assertSame('behind', DailyPerformance::statusFor($rows[$onDefault->id]));

        $this->assertSame(1, (int) $rows[$withOverride->id]->effective_target);
        $this->assertSame('met', DailyPerformance::statusFor($rows[$withOverride->id]));

        $this->assertSame('idle', DailyPerformance::statusFor($rows[$idle->id]));
    }

    public function test_activation_is_credited_to_the_day_it_was_submitted_not_approved(): void
    {
        $tenant = $this->makeTenant(['default_daily_target' => 1]);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $employee = $this->makeUser($tenant, UserRole::Employee, $this->makeOffice($tenant));

        // Did the work yesterday, supervisor approved it this morning.
        $this->complete($tenant, $employee, [
            'submitted_at' => now()->subDay(),
            'completed_at' => now(),
        ]);

        $this->actingAsTenantUser($owner);

        $this->assertSame(0, (int) $this->rows($this->page())[$employee->id]->done_count);
        $this->assertSame(1, (int) $this->rows($this->page(now()->subDay()->toDateString()))[$employee->id]->done_count);
    }

    public function test_awaiting_review_can_reach_target_only_pending_approval(): void
    {
        $tenant = $this->makeTenant(['default_daily_target' => 2]);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $employee = $this->makeUser($tenant, UserRole::Employee, $this->makeOffice($tenant));

        $this->complete($tenant, $employee);
        $this->makeAssignment($tenant, $this->makeAccount($tenant), $employee, [
            'status'       => AccountAssignment::STATUS_AWAITING_REVIEW,
            'submitted_at' => now(),
        ]);

        $this->actingAsTenantUser($owner);
        $row = $this->rows($this->page())[$employee->id];

        $this->assertSame(1, (int) $row->awaiting_count);
        $this->assertSame('awaiting', DailyPerformance::statusFor($row));
    }

    public function test_supervisor_and_manager_only_see_their_own_employees(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $officeA = $this->makeOffice($tenant, ['manager_id' => $manager->id]);
        $officeB = $this->makeOffice($tenant);
        $supervisorB = $this->makeUser($tenant, UserRole::Supervisor, $officeB);
        $employeeA = $this->makeUser($tenant, UserRole::Employee, $officeA);
        $employeeB = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $other = $this->makeTenant();
        $this->makeUser($other, UserRole::Employee, $this->makeOffice($other));

        $this->actingAsTenantUser($manager);
        $this->assertSame([$employeeA->id], $this->rows($this->page())->keys()->all());

        $this->actingAsTenantUser($supervisorB);
        $this->assertSame([$employeeB->id], $this->rows($this->page())->keys()->all());
    }

    public function test_missed_days_counts_previous_week_below_target(): void
    {
        $tenant = $this->makeTenant(['default_daily_target' => 1]);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $employee = $this->makeUser($tenant, UserRole::Employee, $this->makeOffice($tenant));
        $employee->forceFill(['created_at' => now()->subDays(30)])->save();

        // Hit the target on 2 of the previous 7 days.
        $this->complete($tenant, $employee, ['submitted_at' => now()->subDays(1), 'completed_at' => now()->subDays(1)]);
        $this->complete($tenant, $employee, ['submitted_at' => now()->subDays(3), 'completed_at' => now()->subDays(3)]);

        $this->actingAsTenantUser($owner);
        $page = $this->page();

        $this->assertSame(5, $page->missedDays()[$employee->id]);
    }

    public function test_new_employee_is_not_charged_for_days_before_they_joined(): void
    {
        $tenant = $this->makeTenant(['default_daily_target' => 1]);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $employee = $this->makeUser($tenant, UserRole::Employee, $this->makeOffice($tenant));
        $employee->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->actingAsTenantUser($owner);

        $this->assertSame(2, $this->page()->missedDays()[$employee->id]);
    }

    public function test_future_or_garbage_date_falls_back_to_today(): void
    {
        $this->assertSame(now()->toDateString(), $this->page(now()->addDays(3)->toDateString())->day()->toDateString());
        $this->assertSame(now()->toDateString(), $this->page('not-a-date')->day()->toDateString());
    }

    public function test_supervisor_can_view_but_not_change_targets(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->actingAsTenantUser($supervisor);

        Livewire::test(DailyPerformance::class)
            ->assertSuccessful()
            ->assertSee($employee->name)
            ->assertActionHidden('saveDefaultTarget')
            ->assertTableActionHidden('setTarget', $employee);
    }

    public function test_manager_sets_default_and_personal_target(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office = $this->makeOffice($tenant, ['manager_id' => $manager->id]);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $this->actingAsTenantUser($manager);

        Livewire::test(DailyPerformance::class)
            ->set('data.default_daily_target', 8)
            ->callAction('saveDefaultTarget')
            ->callTableAction('setTarget', $employee, ['daily_target' => 4])
            ->assertHasNoTableActionErrors();

        $this->assertSame(8, $tenant->fresh()->default_daily_target);
        $this->assertSame(4, $employee->fresh()->daily_target);
    }

    public function test_employee_cannot_open_the_page(): void
    {
        $tenant = $this->makeTenant();
        $employee = $this->makeUser($tenant, UserRole::Employee, $this->makeOffice($tenant));

        $this->actingAsTenantUser($employee);

        $this->assertFalse(DailyPerformance::canAccess());
    }
}
