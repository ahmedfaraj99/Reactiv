<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
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
        });
    }
}
