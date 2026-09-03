<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Services\TotpService;
use Tests\TestCase;

class ManagerViewCredentialsTest extends TestCase
{
    public function test_modal_never_surfaces_backup_codes_even_when_the_account_has_them(): void
    {
        $tenant  = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $email = 'demo-backup@example.com';
        $account = Account::create([
            'tenant_id'            => $tenant->id,
            'email'                => $email,
            'email_fingerprint'    => Account::fingerprint($email),
            'psn_password'         => 'PsnPass!01',
            'psn_totp_seed'        => 'JBSWY3DPEHPK3PXP',
            'ea_email'             => 'demo-ea@example.com',
            'ea_email_fingerprint' => Account::fingerprint('demo-ea@example.com'),
            'ea_password'          => 'EaPass!001',
            'ea_totp_seed'         => 'NB2W45DFOIZA4TZI',
            'ea_backup_code_1'     => 'AB12-CD34',
            'ea_backup_code_2'     => 'EF56-GH78',
            'status'               => 'available',
            'manager_id'           => $manager->id,
        ]);

        $this->actingAsTenantUser($manager);

        $totp = app(TotpService::class);

        // Match AccountResource::viewCredentials: backup1/backup2 are passed
        // as null even when the account has them — the manager reaches them
        // only by approving an employee's request in the alerts UI.
        $view = view('filament.app.resources.account-resource.credentials-modal', [
            'account'      => $account,
            'psn_email'    => $account->email,
            'psn_password' => $account->psn_password,
            'ea_email'     => $account->effectiveEaEmail(),
            'ea_password'  => $account->effectiveEaPassword(),
            'psn_totp'     => $totp->currentCode($account->psn_totp_seed),
            'ea_totp'      => $totp->currentCode($account->ea_totp_seed),
            'backup1'      => null,
            'backup2'      => null,
        ])->render();

        $this->assertStringContainsString('demo-backup@example.com', $view);
        $this->assertStringContainsString('EaPass!001', $view);
        $this->assertStringNotContainsString('AB12-CD34', $view);
        $this->assertStringNotContainsString('EF56-GH78', $view);
        $this->assertStringNotContainsString('أكواد احتياط', $view);
    }
}
