<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\Activation;
use App\Filament\App\Pages\EndOfDayWipe;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Services\AccountImportService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PSN backup codes mirror the EA pair. Either platform may arrive with
 * backup codes and no 2FA seed; then the codes are its only second factor
 * and the employee sees them straight away. With a seed present they stay
 * behind the supervisor approval, same as before.
 */
class PsnBackupCodesTest extends TestCase
{
    private const HEADER = [
        'email', 'psn_password', 'psn_totp_secret',
        'ea_email', 'ea_password', 'ea_totp_secret',
        'ea_backup_code_1', 'ea_backup_code_2',
        'psn_backup_code_1', 'psn_backup_code_2',
    ];

    /** @param list<list<string>> $rows */
    private function csvFile(array $rows, array $header = self::HEADER): UploadedFile
    {
        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return new UploadedFile($path, 'accounts.csv', 'text/csv', null, true);
    }

    /** @return array{0:\App\Models\Tenant,1:\App\Models\User,2:\App\Models\User} */
    private function importers(): array
    {
        $tenant = $this->makeTenant(['max_accounts' => 10]);

        return [
            $tenant,
            $this->makeUser($tenant, UserRole::TenantOwner),
            $this->makeUser($tenant, UserRole::Manager),
        ];
    }

    public function test_import_stores_psn_backup_codes(): void
    {
        [$tenant, $uploader, $manager] = $this->importers();

        $result = (new AccountImportService())->import($this->csvFile([
            ['a@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', '', 'PS11', 'PS22'],
        ]), $tenant, $uploader, $manager);

        $this->assertSame(1, $result['imported']);
        $account = Account::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('PS11', $account->psn_backup_code_1);
        $this->assertSame('PS22', $account->psn_backup_code_2);
    }

    public function test_import_accepts_blank_2fa_seeds_when_backup_codes_cover_them(): void
    {
        [$tenant, $uploader, $manager] = $this->importers();

        $result = (new AccountImportService())->import($this->csvFile([
            ['a@example.com', 'Pw!1', '', '', '', '', 'EA11', 'EA22', 'PS11', 'PS22'],
        ]), $tenant, $uploader, $manager);

        $this->assertSame(1, $result['imported'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        $account = Account::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNull($account->psn_totp_seed);
        $this->assertNull($account->ea_totp_seed);
    }

    public function test_import_rejects_a_blank_seed_with_no_backup_codes(): void
    {
        [$tenant, $uploader, $manager] = $this->importers();

        $result = (new AccountImportService())->import($this->csvFile([
            ['a@example.com', 'Pw!1', '', '', '', 'NB2W45DFOIZA4TZI', 'EA11', 'EA22', '', ''],
        ]), $tenant, $uploader, $manager);

        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('psn_totp_secret', $result['errors'][0]['reason']);
    }

    public function test_import_rejects_a_single_psn_backup_code(): void
    {
        [$tenant, $uploader, $manager] = $this->importers();

        $result = (new AccountImportService())->import($this->csvFile([
            ['a@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', '', 'PS11', ''],
        ]), $tenant, $uploader, $manager);

        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('PSN', $result['errors'][0]['reason']);
    }

    public function test_old_template_without_psn_columns_still_imports(): void
    {
        [$tenant, $uploader, $manager] = $this->importers();

        $result = (new AccountImportService())->import($this->csvFile(
            [['a@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', '']],
            array_slice(self::HEADER, 0, 8),
        ), $tenant, $uploader, $manager);

        $this->assertSame(1, $result['imported']);
    }

    private function openActivation(array $accountAttrs, array $assignmentAttrs = []): \Livewire\Features\SupportTesting\Testable
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, $accountAttrs);
        $assignment = $this->makeAssignment($tenant, $account, $employee, array_merge([
            'status' => AccountAssignment::STATUS_IN_PROGRESS,
        ], $assignmentAttrs));

        $this->actingAsTenantUser($employee);

        return Livewire::test(Activation::class, ['assignment' => $assignment]);
    }

    public function test_codes_stay_hidden_while_a_2fa_seed_exists_and_no_approval(): void
    {
        $this->openActivation([
            'psn_backup_code_1' => 'PS11', 'psn_backup_code_2' => 'PS22',
            'ea_backup_code_1'  => 'EA11', 'ea_backup_code_2'  => 'EA22',
        ])
            ->assertSet('revealedPsnBackupCode1', null)
            ->assertSet('revealedEaBackupCode1', null)
            ->assertDontSee('PS11');
    }

    public function test_codes_show_directly_when_the_platform_has_no_2fa_seed(): void
    {
        $this->openActivation([
            'psn_totp_seed'     => null,
            'psn_backup_code_1' => 'PS11', 'psn_backup_code_2' => 'PS22',
            'ea_backup_code_1'  => 'EA11', 'ea_backup_code_2'  => 'EA22',
        ])
            ->assertSet('revealedPsnBackupCode1', 'PS11')
            ->assertSet('revealedPsnBackupCode2', 'PS22')
            // EA still has its seed, so its codes keep the approval gate.
            ->assertSet('revealedEaBackupCode1', null)
            ->assertSee('PS11')
            ->assertDontSee('EA11');
    }

    public function test_one_approval_reveals_both_psn_and_ea_codes(): void
    {
        $this->openActivation([
            'psn_backup_code_1' => 'PS11', 'psn_backup_code_2' => 'PS22',
            'ea_backup_code_1'  => 'EA11', 'ea_backup_code_2'  => 'EA22',
        ], ['ea_backup_codes_approved_at' => now()])
            ->assertSet('revealedPsnBackupCode1', 'PS11')
            ->assertSet('revealedEaBackupCode2', 'EA22')
            ->assertSee('PS22')
            ->assertSee('EA11');
    }

    public function test_end_of_day_wipe_clears_psn_backup_codes(): void
    {
        $this->assertContains('psn_backup_code_1', EndOfDayWipe::CREDENTIAL_COLUMNS);
        $this->assertContains('psn_backup_code_2', EndOfDayWipe::CREDENTIAL_COLUMNS);
    }
}
