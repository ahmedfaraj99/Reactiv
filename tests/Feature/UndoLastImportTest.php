<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource\Pages\ListAccounts;
use App\Models\Account;
use App\Models\AccountAdminLog;
use App\Models\AccountAssignment;
use App\Services\AccountImportService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner safety-net: undo the most recent import batch when only untouched
 * accounts are involved. Blocks the delete outright if ANY row in the
 * batch has been opened by an employee (credentials_revealed_at on its
 * assignment) — that would erase real usage evidence.
 */
class UndoLastImportTest extends TestCase
{
    private function importBatch(int $count, string $prefix): array
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $header = ['email','psn_password','psn_totp_secret','ea_email','ea_password','ea_totp_secret','ea_backup_code_1','ea_backup_code_2'];
        $lines = [implode(',', $header)];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = implode(',', ["{$prefix}-{$i}@x.com", 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', '']);
        }
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, implode("\n", $lines));

        (new AccountImportService())->import(
            new UploadedFile($path, 'x.csv', 'text/csv', null, true),
            $tenant, $owner, $manager,
        );

        return compact('tenant', 'owner', 'manager');
    }

    public function test_undo_deletes_the_last_batch_when_no_account_has_been_touched(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->importBatch(3, 'undo');

        $this->assertSame(3, Account::where('tenant_id', $tenant->id)->count());

        $this->actingAsTenantUser($owner);

        Livewire::test(ListAccounts::class)->callTableAction('undoLastImport');

        $this->assertSame(0, Account::where('tenant_id', $tenant->id)->count());
        $this->assertSame(3, AccountAdminLog::where('tenant_id', $tenant->id)
            ->where('action', AccountAdminLog::ACTION_PERMANENTLY_DELETED)->count());
    }

    public function test_undo_only_targets_the_most_recent_batch_leaving_older_ones_untouched(): void
    {
        // First import — 2 accounts.
        ['tenant' => $tenant, 'owner' => $owner, 'manager' => $manager] = $this->importBatch(2, 'old');
        // Second import into the SAME tenant — 3 accounts. These form
        // the "most recent batch"; the older two must survive undo.
        $header = ['email','psn_password','psn_totp_secret','ea_email','ea_password','ea_totp_secret','ea_backup_code_1','ea_backup_code_2'];
        $lines = [implode(',', $header)];
        for ($i = 0; $i < 3; $i++) {
            $lines[] = implode(',', ["new-{$i}@x.com", 'Pw!1', 'JBSWY3DPEHPK3PXP', '', '', 'NB2W45DFOIZA4TZI', '', '']);
        }
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, implode("\n", $lines));
        (new AccountImportService())->import(
            new UploadedFile($path, 'x.csv', 'text/csv', null, true),
            $tenant, $owner, $manager,
        );

        $this->assertSame(5, Account::where('tenant_id', $tenant->id)->count());

        $this->actingAsTenantUser($owner);
        Livewire::test(ListAccounts::class)->callTableAction('undoLastImport');

        // Old batch (2) survives — new batch (3) is gone.
        $this->assertSame(2, Account::where('tenant_id', $tenant->id)->count());
        $this->assertTrue(Account::where('email_fingerprint', Account::fingerprint('old-0@x.com'))->exists());
        $this->assertFalse(Account::where('email_fingerprint', Account::fingerprint('new-0@x.com'))->exists());
    }

    public function test_undo_refuses_when_any_account_in_the_batch_has_been_opened_by_an_employee(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->importBatch(3, 'touched');

        // Simulate an employee opening one of the batch's accounts.
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = Account::where('tenant_id', $tenant->id)->first();
        $this->makeAssignment($tenant, $account, $employee, [
            'status'                  => AccountAssignment::STATUS_IN_PROGRESS,
            'credentials_revealed_at' => now(),
        ]);

        $this->actingAsTenantUser($owner);
        Livewire::test(ListAccounts::class)->callTableAction('undoLastImport');

        // Nothing was deleted — usage evidence is preserved.
        $this->assertSame(3, Account::where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, AccountAdminLog::where('tenant_id', $tenant->id)
            ->where('action', AccountAdminLog::ACTION_PERMANENTLY_DELETED)->count());
    }
}
