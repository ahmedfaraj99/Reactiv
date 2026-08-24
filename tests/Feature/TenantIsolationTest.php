<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\EmployeeExposureReport;
use App\Models\Account;
use App\Models\RevealLog;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two tenants must never see each other's data through any of the
 * App-panel pages, no matter what a client sends. This specifically
 * covers the cross-tenant leak found and fixed in
 * EmployeeExposureReport during this project's security review — the
 * page's employeeId is a public Livewire property, so without an
 * explicit scope check any user could set it to another tenant's
 * employee ID and read their reveal history.
 */
class TenantIsolationTest extends TestCase
{
    public function test_supervisor_cannot_view_another_tenants_employee_exposure_report(): void
    {
        $tenantA = $this->makeTenant();
        $officeA = $this->makeOffice($tenantA);
        $supervisorA = $this->makeUser($tenantA, UserRole::Supervisor, $officeA);

        $tenantB = $this->makeTenant();
        $officeB = $this->makeOffice($tenantB);
        $employeeB = $this->makeUser($tenantB, UserRole::Employee, $officeB);

        $accountB = Account::create([
            'tenant_id'         => $tenantB->id,
            'email'             => 'victim@example.com',
            'email_fingerprint' => Account::fingerprint('victim@example.com'),
            'psn_password'      => 'SecretPw!1',
            'psn_totp_seed'     => 'JBSWY3DPEHPK3PXP',
            'ea_totp_seed'      => 'NB2W45DFOIZA4TZI',
            'status'            => 'assigned',
        ]);

        RevealLog::create([
            'tenant_id'  => $tenantB->id,
            'user_id'    => $employeeB->id,
            'account_id' => $accountB->id,
            'action'     => 'reveal_credentials',
            'created_at' => now(),
        ]);

        $this->actingAsTenantUser($supervisorA);

        $component = Livewire::test(EmployeeExposureReport::class);
        $component->set('employeeId', $employeeB->id);

        $this->assertSame(
            0,
            $component->instance()->getFilteredTableQuery()->count(),
            'A supervisor from tenant A must not see reveal history for an employee belonging to tenant B.',
        );
    }

    public function test_accounts_are_scoped_to_the_tenant_owning_them(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        Account::create([
            'tenant_id'         => $tenantA->id,
            'email'             => 'a-only@example.com',
            'email_fingerprint' => Account::fingerprint('a-only@example.com'),
            'psn_password'      => 'Pw!1',
            'psn_totp_seed'     => 'JBSWY3DPEHPK3PXP',
            'ea_totp_seed'      => 'NB2W45DFOIZA4TZI',
            'status'            => 'available',
        ]);
        Account::create([
            'tenant_id'         => $tenantB->id,
            'email'             => 'b-only@example.com',
            'email_fingerprint' => Account::fingerprint('b-only@example.com'),
            'psn_password'      => 'Pw!2',
            'psn_totp_seed'     => 'JBSWY3DPEHPK3PXP',
            'ea_totp_seed'      => 'NB2W45DFOIZA4TZI',
            'status'            => 'available',
        ]);

        $ownerA = $this->makeUser($tenantA, UserRole::TenantOwner);
        $this->actingAsTenantUser($ownerA);

        $visible = \App\Filament\App\Resources\AccountResource::getEloquentQuery()->pluck('tenant_id')->unique();

        $this->assertEqualsCanonicalizing([$tenantA->id], $visible->all());
    }
}
