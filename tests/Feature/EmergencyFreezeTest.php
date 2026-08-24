<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\Activation;
use App\Filament\App\Widgets\EmergencyFreezeWidget;
use App\Models\AccountAssignment;
use App\Models\Alert;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner-only kill switch. When the tenant is frozen:
 *   - the activation page must NOT reveal credentials (mount treats it
 *     as blocked, same as work-hours);
 *   - TOTP generate actions must refuse to run;
 *   - a freeze/unfreeze event fires a critical alert for the audit trail.
 *
 * Freezing itself is guarded by role (only tenant owner) and by the
 * widget's canView, tested here through the widget's action pipeline.
 */
class EmergencyFreezeTest extends TestCase
{
    public function test_owner_freezing_the_tenant_stamps_reason_and_actor(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);

        $this->actingAsTenantUser($owner);

        Livewire::test(EmergencyFreezeWidget::class)
            ->callAction('freeze', data: ['reason' => 'اشتباه بتسريب من موظف']);

        $fresh = $tenant->fresh();
        $this->assertNotNull($fresh->frozen_at);
        $this->assertSame('اشتباه بتسريب من موظف', $fresh->frozen_reason);
        $this->assertSame($owner->id, $fresh->frozen_by);
        $this->assertSame(1, Alert::where('type', Alert::TYPE_EMERGENCY_FREEZE)
            ->where('payload->action', 'frozen')->count());
    }

    public function test_frozen_tenant_blocks_credential_reveal_on_activation_mount(): void
    {
        $tenant = $this->makeTenant(['frozen_at' => now(), 'frozen_reason' => 'test']);
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS,
        ]);

        $this->actingAsTenantUser($employee);

        $component = Livewire::test(Activation::class, ['assignment' => $assignment]);

        $this->assertNull($component->get('revealedPsnPassword'));
        $this->assertNull($component->get('revealedPsnEmail'));
        $this->assertTrue($component->get('credentialsBlockedByWorkHours'));
    }

    public function test_unfreezing_restores_the_tenant_and_records_the_reversal(): void
    {
        $tenant = $this->makeTenant([
            'frozen_at' => now()->subHour(),
            'frozen_reason' => 'incident',
        ]);
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);

        $this->actingAsTenantUser($owner);

        Livewire::test(EmergencyFreezeWidget::class)->callAction('unfreeze');

        $fresh = $tenant->fresh();
        $this->assertNull($fresh->frozen_at);
        $this->assertNull($fresh->frozen_reason);
        $this->assertSame(1, Alert::where('type', Alert::TYPE_EMERGENCY_FREEZE)
            ->where('payload->action', 'unfrozen')->count());
    }

    public function test_non_owners_cannot_see_the_freeze_widget(): void
    {
        $tenant = $this->makeTenant();
        $manager = $this->makeUser($tenant, UserRole::Manager);

        $this->actingAsTenantUser($manager);

        $this->assertFalse(EmergencyFreezeWidget::canView());
    }
}
