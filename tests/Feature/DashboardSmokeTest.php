<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\DashboardPage;
use App\Filament\App\Widgets\EmployeePerformanceLeaderboardWidget;
use App\Models\AccountAssignment;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Full-page render check for the dashboard with real data flowing through
 * every widget on it, including the two new ones (leaderboard + overview)
 * and the panel's database-notifications bell — this is what a browser
 * would actually load, exercised through Laravel's HTTP test client
 * instead of a real browser. Widgets on this dashboard are all lazy-loaded
 * (standard Filament behavior, true for the pre-existing OverviewStatsWidget
 * too), so the leaderboard's own content is asserted via a direct Livewire
 * component test rather than the initial page HTML, which only ships a
 * loading skeleton for lazy widgets.
 */
class DashboardSmokeTest extends TestCase
{
    public function test_owner_dashboard_renders_without_errors_and_notification_bell_is_registered(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);

        \App\Models\Alert::create([
            'tenant_id' => $tenant->id,
            'type'      => \App\Models\Alert::TYPE_LOGIN_ATTACK,
            'severity'  => 'critical',
            'message'   => 'اختبار',
        ]);

        $this->actingAsTenantUser($owner);

        $response = $this->get(DashboardPage::getUrl());

        $response->assertOk();
        $response->assertSeeLivewire('filament.livewire.database-notifications');
        $response->assertSeeLivewire(EmployeePerformanceLeaderboardWidget::class);
        $this->assertSame(1, $owner->notifications()->count());
    }

    public function test_leaderboard_widget_lists_the_employee_by_name(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'started_at'   => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(20),
        ]);

        $this->actingAsTenantUser($owner);

        Livewire::test(EmployeePerformanceLeaderboardWidget::class)
            ->assertSee('الأعلى أداءً')
            ->assertSee($employee->name);
    }
}
