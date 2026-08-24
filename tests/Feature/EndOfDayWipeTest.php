<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\EndOfDayWipe;
use App\Models\AccountAssignment;
use App\Models\Client;
use App\Models\WipeLog;
use Tests\TestCase;

/**
 * The End-of-Day workflow is the biggest lever for reducing blast radius —
 * it must wipe exactly what it promises (six credential columns) and
 * nothing more (email/timestamps/reveal_logs are the audit trail).
 */
class EndOfDayWipeTest extends TestCase
{
    public function test_wipe_nulls_credentials_and_preserves_audit_fields(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'العميل ١']);
        $account = $this->makeAccount($tenant, ['client_id' => $client->id]);
        $originalEmail = $account->email;
        $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAsTenantUser($owner);

        $rows = (new EndOfDayWipe())->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['count']);

        // Exercise the wipe path directly.
        $reflection = new \ReflectionMethod(EndOfDayWipe::class, 'wipeClient');
        $reflection->setAccessible(true);
        $page = new EndOfDayWipe();
        $reflection->invoke($page, $client->id);

        $account->refresh();
        foreach (EndOfDayWipe::CREDENTIAL_COLUMNS as $col) {
            $this->assertNull($account->{$col}, "Column {$col} must be null after wipe");
        }
        $this->assertSame($originalEmail, $account->email, 'Email must survive the wipe');
        $this->assertNotNull($account->client_id, 'client_id must survive the wipe');

        // A second call sees zero eligible rows and doesn't double-wipe.
        $this->assertSame(0, (new EndOfDayWipe())->rows() === [] ? 0 : (new EndOfDayWipe())->rows()[0]['count']);

        $this->assertSame(1, WipeLog::query()->where('tenant_id', $tenant->id)->count());
        $log = WipeLog::query()->first();
        $this->assertSame(1, $log->accounts_wiped);
        $this->assertSame($owner->id, $log->wiped_by);
        $this->assertSame($client->id, $log->client_id);
    }

    public function test_owner_cannot_wipe_client_from_another_tenant(): void
    {
        // Owner of tenant A crafts a Livewire payload with the client_id of
        // a Client that belongs to tenant B. Even if canAccess were bypassed,
        // the tenant-scoped findOrFail must refuse.
        $tenantA = $this->makeTenant();
        $ownerA  = $this->makeUser($tenantA, UserRole::TenantOwner);

        $tenantB = $this->makeTenant();
        $officeB = $this->makeOffice($tenantB);
        $empB    = $this->makeUser($tenantB, UserRole::Employee, $officeB);
        $clientB = Client::create(['tenant_id' => $tenantB->id, 'name' => 'B']);
        $accountB = $this->makeAccount($tenantB, ['client_id' => $clientB->id]);
        $this->makeAssignment($tenantB, $accountB, $empB, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAsTenantUser($ownerA);

        $reflection = new \ReflectionMethod(EndOfDayWipe::class, 'wipeClient');
        $reflection->setAccessible(true);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $reflection->invoke(new EndOfDayWipe(), $clientB->id);
    }

    public function test_non_owner_is_refused_at_the_action_level(): void
    {
        // Even if a manager somehow reaches the action (replayed Livewire
        // payload, direct call), the abort_unless inside must stop them.
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $manager = $this->makeUser($tenant, UserRole::Manager, $office);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'X']);
        $account = $this->makeAccount($tenant, ['client_id' => $client->id]);
        $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAsTenantUser($manager);

        $reflection = new \ReflectionMethod(EndOfDayWipe::class, 'wipeClient');
        $reflection->setAccessible(true);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $reflection->invoke(new EndOfDayWipe(), $client->id);
    }

    public function test_csv_contains_emails_and_is_populated_even_after_the_wipe_nulls_the_row(): void
    {
        // Regression guard for the streamed-response ordering: the CSV
        // closure captures a pre-loaded Collection, so the emails must
        // appear even though the DB row's credentials are already null
        // by the time the closure runs.
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office, ['name' => 'الموظف س']);

        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'العميل ١']);
        $account = $this->makeAccount($tenant, [
            'client_id' => $client->id,
            'email'     => 'target@example.com',
        ]);
        $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAsTenantUser($owner);

        $reflection = new \ReflectionMethod(EndOfDayWipe::class, 'wipeClient');
        $reflection->setAccessible(true);
        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = $reflection->invoke(new EndOfDayWipe(), $client->id);

        // Streamed responses only produce their body when sent — capture
        // it via output buffering so we can inspect the CSV contents.
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('target@example.com', $csv, 'CSV must include the account email');
        $this->assertStringContainsString('الموظف س', $csv, 'CSV must include the employee name');
        $this->assertStringNotContainsString('PsnPw!1', $csv, 'CSV must NOT include the password');
    }

    public function test_wipe_ignores_accounts_belonging_to_a_different_client(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);

        $clientA = Client::create(['tenant_id' => $tenant->id, 'name' => 'A']);
        $clientB = Client::create(['tenant_id' => $tenant->id, 'name' => 'B']);

        $accountA = $this->makeAccount($tenant, ['client_id' => $clientA->id]);
        $accountB = $this->makeAccount($tenant, ['client_id' => $clientB->id]);

        foreach ([$accountA, $accountB] as $a) {
            $this->makeAssignment($tenant, $a, $employee, [
                'status'       => AccountAssignment::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        $this->actingAsTenantUser($owner);

        $reflection = new \ReflectionMethod(EndOfDayWipe::class, 'wipeClient');
        $reflection->setAccessible(true);
        $reflection->invoke(new EndOfDayWipe(), $clientA->id);

        $accountA->refresh();
        $accountB->refresh();

        $this->assertNull($accountA->psn_password, 'Client A account must be wiped');
        $this->assertNotNull($accountB->psn_password, 'Client B account must NOT be touched');
    }
}
