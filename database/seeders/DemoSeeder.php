<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Office;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DemoSeeder extends Seeder
{
    /**
     * Idempotent: running the seeder twice does not duplicate rows.
     * All test users share the same password so it's easy to log in
     * to any of them during exploration.
     */
    private const PASSWORD = 'Password!123';

    public function run(): void
    {
        $this->call(RolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $tenant = Tenant::firstOrCreate(
                ['slug' => 'demo'],
                [
                    'name'          => 'شركة الديمو التجريبية',
                    'subdomain'     => 'demo',
                    'status'        => 'active',
                    'plan'          => 'pro',
                    'max_accounts'  => 5000,
                    'max_employees' => 100,
                ],
            );

            $owner = $this->makeUser($tenant, null, 'owner@demo.local', 'مالك الديمو', UserRole::TenantOwner);

            $manager = $this->makeUser($tenant, null, 'manager@demo.local', 'مدير الديمو', UserRole::Manager);

            $office = Office::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'مكتب طرابلس'],
                ['city' => 'طرابلس', 'active' => true],
            );

            // Manager is responsible for this office (offices.manager_id).
            $office->forceFill(['manager_id' => $manager->id])->save();

            $supervisor = $this->makeUser($tenant, $office, 'supervisor@demo.local', 'مشرف مكتب طرابلس', UserRole::Supervisor);
            $employee   = $this->makeUser($tenant, $office, 'employee@demo.local', 'موظف الديمو', UserRole::Employee);

            $accounts = $this->makeAccounts($tenant, $owner, $manager);

            // Pre-assign the first account so the employee lands on
            // something ready to activate.
            $firstAccount = $accounts->first();
            AccountAssignment::firstOrCreate(
                ['account_id' => $firstAccount->id],
                [
                    'tenant_id'     => $tenant->id,
                    'employee_id'   => $employee->id,
                    'supervisor_id' => $supervisor->id,
                    'status'        => 'pending',
                    'assigned_at'   => now(),
                ],
            );
            $firstAccount->update(['status' => 'assigned']);
        });
    }

    private function makeUser(Tenant $tenant, ?Office $office, string $email, string $name, UserRole $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'tenant_id' => $tenant->id,
                'office_id' => $office?->id,
                'name'      => $name,
                'password'  => Hash::make(self::PASSWORD),
                'active'    => true,
            ],
        );

        // Ensure the tenant/office links are correct even if the row pre-existed.
        $user->forceFill([
            'tenant_id' => $tenant->id,
            'office_id' => $office?->id,
        ])->save();

        if (! $user->hasRole($role->value)) {
            $user->syncRoles([$role->value]);
        }

        return $user;
    }

    /** @return \Illuminate\Support\Collection<int, Account> */
    private function makeAccounts(Tenant $tenant, User $uploader, User $manager)
    {
        // Real Base32 TOTP secrets — these actually generate valid 2FA codes.
        // JBSWY3DPEHPK3PXP is the classic google2fa test vector; add these
        // to Google Authenticator manually to see codes matching the app.
        // Each account is one email + a PSN pair + an EA pair. Account #1
        // shows the common case (EA shares the primary email); account #2
        // shows the exceptional case (EA has its own email) plus EA backup
        // codes — the manager's "view credentials" modal only surfaces the
        // backup-codes row when the pair is present.
        $seeds = [
            [
                'email' => 'demo-1@example.com', 'psnPassword' => 'PsnPass!01', 'psnSeed' => 'JBSWY3DPEHPK3PXP',
                'eaEmail' => null, 'eaPassword' => 'EaPass!001', 'eaSeed' => 'ONSWG4TFOQ2XG4Q=',
                'backup1' => null, 'backup2' => null,
            ],
            [
                'email' => 'demo-psn-2@example.com', 'psnPassword' => 'PsnPass!02', 'psnSeed' => 'NB2W45DFOIZA4TZI',
                'eaEmail' => 'demo-ea-2@example.com', 'eaPassword' => 'EaPass!002', 'eaSeed' => 'MFRGGZDFMZTWQ2LK',
                'backup1' => 'AB12-CD34', 'backup2' => 'EF56-GH78',
            ],
        ];

        $created = collect();
        foreach ($seeds as $seed) {
            $emailFp = Account::fingerprint($seed['email']);
            $eaFp    = $seed['eaEmail'] !== null ? Account::fingerprint($seed['eaEmail']) : null;

            $account = Account::firstOrCreate(
                ['tenant_id' => $tenant->id, 'email_fingerprint' => $emailFp],
                [
                    'email'                => $seed['email'],
                    'psn_password'         => $seed['psnPassword'],
                    'psn_totp_seed'        => $seed['psnSeed'],
                    'ea_email'             => $seed['eaEmail'],
                    'ea_email_fingerprint' => $eaFp,
                    'ea_password'          => $seed['eaPassword'],
                    'ea_totp_seed'         => $seed['eaSeed'],
                    'ea_backup_code_1'     => $seed['backup1'],
                    'ea_backup_code_2'     => $seed['backup2'],
                    'status'               => 'available',
                    'uploaded_by'          => $uploader->id,
                    'manager_id'           => $manager->id,
                ],
            );

            // Re-apply the fields firstOrCreate skips on a hit — new
            // columns added in later migrations (manager_id, ea_backup_code_*)
            // stay null on pre-existing demo rows without this. Safe to
            // rewrite on every run: these are deterministic seed values.
            $account->update([
                'ea_backup_code_1' => $seed['backup1'],
                'ea_backup_code_2' => $seed['backup2'],
                'manager_id'       => $manager->id,
            ]);

            $created->push($account);
        }

        return $created;
    }
}
