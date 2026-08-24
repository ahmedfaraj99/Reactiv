<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Services\AccountImportService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * matches_required is chosen on the owner's upload form, not per-row in
 * the spreadsheet — a single upload = a single customer's tier of
 * service, so all accounts in the batch share the same value. Default
 * 0 keeps the "activation only" behaviour for anyone using the old
 * form or the old service signature.
 */
class MatchesRequiredImportTest extends TestCase
{
    /** @param  list<list<mixed>>  $rows */
    private function csvFile(array $rows): UploadedFile
    {
        $header = [
            'email', 'psn_password', 'psn_totp_secret',
            'ea_email', 'ea_password', 'ea_totp_secret',
            'ea_backup_code_1', 'ea_backup_code_2',
        ];

        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => (string) $v, $row));
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return new UploadedFile($path, 'accounts.csv', 'text/csv', null, true);
    }

    public function test_import_without_matches_argument_defaults_to_activation_only(): void
    {
        $tenant = $this->makeTenant();
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $csv = $this->csvFile([
            ['a@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
        ]);

        $result = (new AccountImportService())->import($csv, $tenant, $uploader, $manager);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, Account::where('tenant_id', $tenant->id)->first()->matches_required);
    }

    public function test_batch_level_matches_required_is_applied_to_every_account_in_the_upload(): void
    {
        $tenant = $this->makeTenant();
        $uploader = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $csv = $this->csvFile([
            ['a@example.com', 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', ''],
            ['b@example.com', 'Pw!2', 'NB2W45DFOIZA4TZI', '', '', 'MFRGGZDFMZTWQ2LK', '', ''],
            ['c@example.com', 'Pw!3', 'MFRGGZDFMZTWQ2LK', '', '', 'JBSWY3DPEHPK3PXP', '', ''],
        ]);

        $result = (new AccountImportService())->import($csv, $tenant, $uploader, $manager, matchesRequired: 3);

        $this->assertSame(3, $result['imported']);
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $account = Account::where('email_fingerprint', Account::fingerprint($email))->first();
            $this->assertSame(3, $account->matches_required, "expected 3 for {$email}");
        }
    }

    public function test_requires_matches_helper_reflects_the_value(): void
    {
        $tenant = $this->makeTenant();

        $activationOnly = $this->makeAccount($tenant, ['matches_required' => 0]);
        $withMatches    = $this->makeAccount($tenant, ['email' => 'x@example.com', 'email_fingerprint' => Account::fingerprint('x@example.com'), 'matches_required' => 3]);

        $this->assertFalse($activationOnly->requiresMatches());
        $this->assertTrue($withMatches->requiresMatches());
    }
}
