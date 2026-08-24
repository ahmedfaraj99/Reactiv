<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class ProductionSeeder extends Seeder
{
    private const OWNER_EMAIL    = 'ahmedfar999@gmail.com';
    private const OWNER_PASSWORD = '12345';
    private const OWNER_NAME     = 'Ahmed Faraj';

    public function run(): void
    {
        $this->call(RolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $tenant = Tenant::firstOrCreate(
                ['slug' => 'main'],
                [
                    'name'          => 'Reactiv',
                    'subdomain'     => 'main',
                    'status'        => 'active',
                    'plan'          => 'pro',
                    'max_accounts'  => 5000,
                    'max_employees' => 100,
                ],
            );

            $owner = User::firstOrCreate(
                ['email' => self::OWNER_EMAIL],
                [
                    'tenant_id' => $tenant->id,
                    'office_id' => null,
                    'name'      => self::OWNER_NAME,
                    'password'  => Hash::make(self::OWNER_PASSWORD),
                    'active'    => true,
                ],
            );

            $owner->forceFill([
                'tenant_id' => $tenant->id,
                'office_id' => null,
                'active'    => true,
            ])->save();

            if (! $owner->hasRole(UserRole::TenantOwner->value)) {
                $owner->syncRoles([UserRole::TenantOwner->value]);
            }

            $this->makeTestAccounts($tenant, $owner);
        });
    }

    private function makeTestAccounts(Tenant $tenant, User $uploader): void
    {
        $seeds = [
            [
                'email' => 'test-1@example.com', 'psnPassword' => 'PsnPass!01', 'psnSeed' => 'JBSWY3DPEHPK3PXP',
                'eaEmail' => null, 'eaPassword' => 'EaPass!001', 'eaSeed' => 'ONSWG4TFOQ2XG4Q=',
            ],
            [
                'email' => 'test-psn-2@example.com', 'psnPassword' => 'PsnPass!02', 'psnSeed' => 'NB2W45DFOIZA4TZI',
                'eaEmail' => 'test-ea-2@example.com', 'eaPassword' => 'EaPass!002', 'eaSeed' => 'MFRGGZDFMZTWQ2LK',
            ],
            [
                'email' => 'test-3@example.com', 'psnPassword' => 'PsnPass!03', 'psnSeed' => 'GEZDGNBVGY3TQOJQ',
                'eaEmail' => null, 'eaPassword' => 'EaPass!003', 'eaSeed' => 'MZXW6YTBOI======',
            ],
        ];

        foreach ($seeds as $seed) {
            $emailFp = Account::fingerprint($seed['email']);
            $eaFp    = $seed['eaEmail'] !== null ? Account::fingerprint($seed['eaEmail']) : null;

            Account::firstOrCreate(
                ['tenant_id' => $tenant->id, 'email_fingerprint' => $emailFp],
                [
                    'email'                => $seed['email'],
                    'psn_password'         => $seed['psnPassword'],
                    'psn_totp_seed'        => $seed['psnSeed'],
                    'ea_email'             => $seed['eaEmail'],
                    'ea_email_fingerprint' => $eaFp,
                    'ea_password'          => $seed['eaPassword'],
                    'ea_totp_seed'         => $seed['eaSeed'],
                    'status'               => 'available',
                    'uploaded_by'          => $uploader->id,
                ],
            );
        }
    }
}
