<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Office;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (UserRole::cases() as $role) {
            Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web']);
        }
    }

    protected function makeTenant(array $attributes = []): Tenant
    {
        return Tenant::create(array_merge([
            'name'          => 'شركة اختبار',
            'slug'          => 'test-'.uniqid(),
            'status'        => 'active',
            'plan'          => 'pro',
            'max_accounts'  => 5000,
            'max_employees' => 100,
        ], $attributes));
    }

    protected function makeOffice(Tenant $tenant, array $attributes = []): Office
    {
        return Office::create(array_merge([
            'tenant_id' => $tenant->id,
            'name'      => 'مكتب اختبار',
            'city'      => 'طرابلس',
            'active'    => true,
        ], $attributes));
    }

    protected function makeUser(Tenant $tenant, UserRole $role, ?Office $office = null, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'tenant_id' => $tenant->id,
            'office_id' => $office?->id,
            'name'      => $role->label().' اختبار',
            'email'     => strtolower($role->value).'-'.uniqid().'@test.local',
            'password'  => Hash::make('Password!123'),
            'active'    => true,
        ], $attributes));

        $user->assignRole($role->value);

        return $user;
    }

    protected function makeAccount(Tenant $tenant, array $attributes = []): Account
    {
        $email = $attributes['email'] ?? 'account-'.uniqid().'@example.com';

        return Account::create(array_merge([
            'tenant_id'         => $tenant->id,
            'email'             => $email,
            'email_fingerprint' => Account::fingerprint($email),
            'psn_password'      => 'PsnPw!1',
            'psn_totp_seed'     => 'JBSWY3DPEHPK3PXP',
            'ea_totp_seed'      => 'NB2W45DFOIZA4TZI',
            'status'            => 'available',
        ], $attributes));
    }

    protected function makeAssignment(Tenant $tenant, Account $account, User $employee, array $attributes = []): AccountAssignment
    {
        return AccountAssignment::create(array_merge([
            'tenant_id'   => $tenant->id,
            'account_id'  => $account->id,
            'employee_id' => $employee->id,
            'status'      => AccountAssignment::STATUS_PENDING,
            'assigned_at' => now(),
        ], $attributes));
    }

    /**
     * Log in as $user inside the 'app' panel with their tenant set as the
     * active Filament tenant — the context every App-panel page/resource
     * assumes is already in place.
     */
    protected function actingAsTenantUser(User $user): static
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('app'));
        $this->actingAs($user);
        \Filament\Facades\Filament::setTenant($user->tenant);

        return $this;
    }
}
