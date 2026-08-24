<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource;
use App\Models\AccountAssignment;
use Tests\TestCase;

class AccountResourceTest extends TestCase
{
    public function test_reassigning_an_account_wipes_the_previous_attempts_progress(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $failedEmployee = $this->makeUser($tenant, UserRole::Employee, $office);
        $freshEmployee = $this->makeUser($tenant, UserRole::Employee, $office);

        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $this->makeAssignment($tenant, $account, $failedEmployee, [
            'status'               => AccountAssignment::STATUS_FAILED,
            'psn_totp_generations' => 1,
            'ea_totp_generations'  => 2,
            'notes'                => 'بيانات خطأ: بريد PSN',
            'completed_at'         => now(),
        ]);

        $this->actingAsTenantUser($supervisor);

        AccountResource::assignAccounts(collect([$account]), $freshEmployee->id);

        $assignment = AccountAssignment::where('account_id', $account->id)->firstOrFail();

        $this->assertSame($freshEmployee->id, $assignment->employee_id);
        $this->assertSame(AccountAssignment::STATUS_PENDING, $assignment->status);
        $this->assertSame(0, $assignment->psn_totp_generations);
        $this->assertSame(0, $assignment->ea_totp_generations);
        $this->assertNull($assignment->notes);
        $this->assertNull($assignment->completed_at);
        $this->assertSame('assigned', $account->fresh()->status);
    }

    public function test_supervisor_can_only_manage_accounts_in_their_own_office(): void
    {
        $tenant = $this->makeTenant();
        $officeA = $this->makeOffice($tenant, ['name' => 'مكتب أ']);
        $officeB = $this->makeOffice($tenant, ['name' => 'مكتب ب']);

        $supervisorA = $this->makeUser($tenant, UserRole::Supervisor, $officeA);
        $employeeB = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $accountUnassigned = $this->makeAccount($tenant, ['email' => 'u@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('u@example.com')]);
        $accountForB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@example.com'), 'status' => 'assigned']);
        $this->makeAssignment($tenant, $accountForB, $employeeB);

        $this->actingAsTenantUser($supervisorA);

        $canManage = new \ReflectionMethod(AccountResource::class, 'canManage');
        $canManage->setAccessible(true);

        $this->assertTrue($canManage->invoke(null, $accountUnassigned->fresh()));
        $this->assertFalse($canManage->invoke(null, $accountForB->fresh(['assignment.employee'])));
    }

    public function test_manager_can_manage_accounts_across_offices_they_run(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office->update(['manager_id' => $manager->id]);

        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['status' => 'assigned']);
        $this->makeAssignment($tenant, $account, $employee);

        $this->actingAsTenantUser($manager);

        $canManage = new \ReflectionMethod(AccountResource::class, 'canManage');
        $canManage->setAccessible(true);

        $this->assertTrue($canManage->invoke(null, $account->fresh(['assignment.employee'])));
    }

    public function test_mask_email_hides_the_local_part(): void
    {
        $masked = AccountResource::maskEmail('someone@example.com');

        $this->assertStringNotContainsString('someone', $masked);
        $this->assertStringContainsString('@example.com', $masked);
    }

    public function test_failed_export_csv_matches_the_import_column_format(): void
    {
        $tenant = $this->makeTenant();
        $account = $this->makeAccount($tenant, [
            'email'            => 'client,with,commas@example.com',
            'email_fingerprint' => \App\Models\Account::fingerprint('client,with,commas@example.com'),
            'ea_backup_code_1' => 'BACKUP1',
            'ea_backup_code_2' => 'BACKUP2',
        ]);

        $build = new \ReflectionMethod(AccountResource::class, 'buildFailedExportCsv');
        $build->setAccessible(true);

        $csv = $build->invoke(null, collect([$account]));

        $rows = array_map('str_getcsv', explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $csv))));

        $this->assertSame([
            'email', 'psn_password', 'psn_totp_secret',
            'ea_email', 'ea_password', 'ea_totp_secret',
            'ea_backup_code_1', 'ea_backup_code_2', 'سبب_الخطأ',
        ], array_slice($rows[0], 0, 9));

        // The comma-containing email must round-trip intact as a single
        // quoted field, not get split into extra columns.
        $this->assertSame('client,with,commas@example.com', $rows[1][0]);
        $this->assertSame('BACKUP1', $rows[1][6]);
        $this->assertSame('BACKUP2', $rows[1][7]);
    }
}
