<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Widgets\OverviewStatsWidget;
use App\Models\Account;
use Tests\TestCase;

/**
 * A manager must never see another manager's numbers — not their
 * accounts, and not even the aggregate stats on the dashboard. Before
 * this fix, the "available accounts" stat counted every unassigned
 * account in the whole tenant for every manager/supervisor, because
 * the widget's scoping predated the accounts.manager_id column and
 * was never updated to use it.
 */
class OverviewStatsWidgetTest extends TestCase
{
    /** @return array<string,mixed> */
    private function statsByLabel(OverviewStatsWidget $widget): array
    {
        $method = new \ReflectionMethod(OverviewStatsWidget::class, 'getStats');
        $method->setAccessible(true);
        $stats = $method->invoke($widget);

        $out = [];
        foreach ($stats as $stat) {
            $out[$stat->getLabel()] = $stat->getValue();
        }

        return $out;
    }

    public function test_manager_available_accounts_count_excludes_another_managers_batch(): void
    {
        $tenant = $this->makeTenant();
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);

        $this->makeAccount($tenant, ['email' => 'a1@example.com', 'email_fingerprint' => Account::fingerprint('a1@example.com'), 'manager_id' => $managerA->id, 'status' => 'available']);
        $this->makeAccount($tenant, ['email' => 'a2@example.com', 'email_fingerprint' => Account::fingerprint('a2@example.com'), 'manager_id' => $managerA->id, 'status' => 'available']);
        $this->makeAccount($tenant, ['email' => 'b1@example.com', 'email_fingerprint' => Account::fingerprint('b1@example.com'), 'manager_id' => $managerB->id, 'status' => 'available']);
        $this->makeAccount($tenant, ['email' => 'b2@example.com', 'email_fingerprint' => Account::fingerprint('b2@example.com'), 'manager_id' => $managerB->id, 'status' => 'available']);
        $this->makeAccount($tenant, ['email' => 'b3@example.com', 'email_fingerprint' => Account::fingerprint('b3@example.com'), 'manager_id' => $managerB->id, 'status' => 'available']);

        $this->actingAsTenantUser($managerA);

        $stats = $this->statsByLabel(new OverviewStatsWidget());

        $this->assertSame('2', $stats['حسابات متاحة']);
    }

    public function test_supervisor_available_accounts_count_is_scoped_to_their_managers_batch(): void
    {
        $tenant = $this->makeTenant();
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);
        $officeUnderA = $this->makeOffice($tenant, ['manager_id' => $managerA->id]);
        $supervisorUnderA = $this->makeUser($tenant, UserRole::Supervisor, $officeUnderA);

        $this->makeAccount($tenant, ['email' => 'a1@example.com', 'email_fingerprint' => Account::fingerprint('a1@example.com'), 'manager_id' => $managerA->id, 'status' => 'available']);
        $this->makeAccount($tenant, ['email' => 'b1@example.com', 'email_fingerprint' => Account::fingerprint('b1@example.com'), 'manager_id' => $managerB->id, 'status' => 'available']);

        $this->actingAsTenantUser($supervisorUnderA);

        $stats = $this->statsByLabel(new OverviewStatsWidget());

        $this->assertSame('1', $stats['حسابات متاحة']);
    }

    public function test_owner_sees_every_managers_available_accounts_combined(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $managerA = $this->makeUser($tenant, UserRole::Manager);
        $managerB = $this->makeUser($tenant, UserRole::Manager);

        $this->makeAccount($tenant, ['email' => 'a1@example.com', 'email_fingerprint' => Account::fingerprint('a1@example.com'), 'manager_id' => $managerA->id, 'status' => 'available']);
        $this->makeAccount($tenant, ['email' => 'b1@example.com', 'email_fingerprint' => Account::fingerprint('b1@example.com'), 'manager_id' => $managerB->id, 'status' => 'available']);

        $this->actingAsTenantUser($owner);

        $stats = $this->statsByLabel(new OverviewStatsWidget());

        $this->assertSame('2', $stats['حسابات متاحة']);
    }
}
