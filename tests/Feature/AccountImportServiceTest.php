<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Services\AccountImportService;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

class AccountImportServiceTest extends TestCase
{
    private const HEADER = [
        'email', 'psn_password', 'psn_totp_secret',
        'ea_email', 'ea_password', 'ea_totp_secret',
        'ea_backup_code_1', 'ea_backup_code_2',
    ];

    private function csvFile(array $rows): UploadedFile
    {
        $lines = [implode(',', self::HEADER)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => (string) $v, $row));
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return new UploadedFile($path, 'accounts.csv', 'text/csv', null, true);
    }

    public function test_import_stops_accepting_rows_once_the_tenant_hits_max_accounts(): void
    {
        $tenant = $this->makeTenant(['max_accounts' => 1]);
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $this->makeAccount($tenant, ['email' => 'existing@example.com', 'email_fingerprint' => Account::fingerprint('existing@example.com')]);

        $csv = $this->csvFile([
            ['new@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
        ]);

        $result = (new AccountImportService())->import($csv, $tenant, $uploader, $manager);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('تجاوزت الحد الأقصى', $result['errors'][0]['reason']);
        $this->assertSame(1, Account::where('tenant_id', $tenant->id)->count());
    }

    public function test_activated_and_retired_accounts_do_not_count_toward_max_accounts(): void
    {
        $tenant = $this->makeTenant(['max_accounts' => 1]);
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $this->makeAccount($tenant, ['email' => 'done@example.com', 'email_fingerprint' => Account::fingerprint('done@example.com'), 'status' => 'activated']);
        $this->makeAccount($tenant, ['email' => 'old@example.com', 'email_fingerprint' => Account::fingerprint('old@example.com'), 'status' => 'retired']);

        $csv = $this->csvFile([
            ['new@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
        ]);

        $result = (new AccountImportService())->import($csv, $tenant, $uploader, $manager);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_import_allows_rows_under_the_max_accounts_limit(): void
    {
        $tenant = $this->makeTenant(['max_accounts' => 5]);
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $csv = $this->csvFile([
            ['a@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
            ['b@example.com', 'Pw!2', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
        ]);

        $result = (new AccountImportService())->import($csv, $tenant, $uploader, $manager);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, Account::where('tenant_id', $tenant->id)->count());
    }

    public function test_import_skips_a_row_whose_email_already_exists_for_the_tenant(): void
    {
        $tenant = $this->makeTenant(['max_accounts' => 10]);
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $this->makeAccount($tenant, ['email' => 'dup@example.com', 'email_fingerprint' => Account::fingerprint('dup@example.com')]);

        $csv = $this->csvFile([
            ['dup@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
        ]);

        $result = (new AccountImportService())->import($csv, $tenant, $uploader, $manager);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, Account::where('tenant_id', $tenant->id)->count());
    }

    public function test_import_rejects_a_row_with_only_one_backup_code(): void
    {
        $tenant = $this->makeTenant(['max_accounts' => 10]);
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $csv = $this->csvFile([
            ['solo@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', 'ONLYONE', ''],
        ]);

        $result = (new AccountImportService())->import($csv, $tenant, $uploader, $manager);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('كود الاحتياط', $result['errors'][0]['reason']);
    }

    public function test_imported_accounts_are_stamped_with_the_chosen_manager(): void
    {
        $tenant = $this->makeTenant(['max_accounts' => 10]);
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $csv = $this->csvFile([
            ['stamped@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
        ]);

        (new AccountImportService())->import($csv, $tenant, $uploader, $manager);

        $account = Account::where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame($manager->id, $account->manager_id);
        $this->assertSame($uploader->id, $account->uploaded_by);
    }

    public function test_import_rejects_an_invalid_totp_secret(): void
    {
        $tenant = $this->makeTenant(['max_accounts' => 10]);
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $csv = $this->csvFile([
            ['bad@example.com', 'Pw!1', 'not-base32!!', '', '', 'NB2W45DFOIZA4TZI', '', ''],
        ]);

        $result = (new AccountImportService())->import($csv, $tenant, $uploader, $manager);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
    }

    /** @param  list<list<mixed>>  $rows */
    private function xlsxFile(array $rows, array $header = self::HEADER): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';

        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));
        $dateStyle = (new Style())->setFormat('yyyy-mm-dd');
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(
                fn ($v) => Cell::fromValue($v, $v instanceof DateTimeInterface ? $dateStyle : null),
                $row,
            )));
        }
        $writer->close();

        return new UploadedFile($path, 'accounts.xlsx', null, null, true);
    }

    public function test_a_credential_excel_turned_into_a_date_fails_that_row_only(): void
    {
        $tenant = $this->makeTenant(['max_accounts' => 10]);
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $xlsx = $this->xlsxFile([
            ['date@example.com', new DateTimeImmutable('2026-03-12'), 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
            ['fine@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
        ]);

        $result = (new AccountImportService())->import($xlsx, $tenant, $uploader, $manager);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('date@example.com', $result['errors'][0]['email']);
        $this->assertStringContainsString('psn_password', $result['errors'][0]['reason']);
        $this->assertFalse(Account::where('email_fingerprint', Account::fingerprint('date@example.com'))->exists());
    }

    public function test_a_date_in_a_column_the_import_ignores_is_harmless(): void
    {
        $tenant = $this->makeTenant(['max_accounts' => 10]);
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $xlsx = $this->xlsxFile(
            [['fine@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', '', new DateTimeImmutable('2026-03-12')]],
            [...self::HEADER, 'purchase_date'],
        );

        $result = (new AccountImportService())->import($xlsx, $tenant, $uploader, $manager);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['failed']);
    }
}
