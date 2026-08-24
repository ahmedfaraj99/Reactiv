<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\Activation;
use App\Models\AccountAssignment;
use App\Models\RevealLog;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Activation::refreshTotpDisplay silently rolls the *visible* code
 * forward when the 30-second window ends, so the one paid-for
 * generation stays useful across as many windows as the employee needs
 * to type it in. The rule: same seed, same allowance count, no new
 * reveal_log row. Only callable when a code is already displayed —
 * otherwise it would leak a code the employee hadn't paid for.
 */
class TotpRefreshDisplayTest extends TestCase
{
    public function test_refresh_recomputes_the_visible_code_without_bumping_the_allowance_counter(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'               => AccountAssignment::STATUS_IN_PROGRESS,
            'psn_totp_generations' => 1,
        ]);

        $this->actingAsTenantUser($employee);

        $component = Livewire::test(Activation::class, ['assignment' => $assignment])
            ->set('totpCodePsn', '123456')
            ->set('totpSecondsLeft', 0)
            ->call('refreshTotpDisplay');

        $this->assertNotNull($component->get('totpCodePsn'));
        $this->assertNotEmpty($component->get('totpCodePsn'));
        $this->assertGreaterThan(0, $component->get('totpSecondsLeft'));
        // The counter that enforces the 1-per-activation limit stays exactly
        // where it was — refresh is just "let me see the current window",
        // not "give me another generation".
        $this->assertSame(1, $assignment->fresh()->psn_totp_generations);
    }

    public function test_refresh_writes_no_reveal_log_entry(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'              => AccountAssignment::STATUS_IN_PROGRESS,
            'ea_totp_generations' => 1,
        ]);

        $this->actingAsTenantUser($employee);

        // mount() itself writes one reveal_credentials row — that's the
        // paid-for view. Capture the baseline AFTER mount so this test is
        // scoped to what refreshTotpDisplay does or doesn't add on top.
        $component = Livewire::test(Activation::class, ['assignment' => $assignment])
            ->set('totpCodeEa', '654321');

        $before = RevealLog::query()->count();

        $component->call('refreshTotpDisplay');

        $this->assertSame($before, RevealLog::query()->count());
    }

    public function test_refresh_is_a_no_op_when_no_code_is_currently_displayed(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status' => AccountAssignment::STATUS_IN_PROGRESS,
        ]);

        $this->actingAsTenantUser($employee);

        $component = Livewire::test(Activation::class, ['assignment' => $assignment])
            ->call('refreshTotpDisplay');

        // Nothing was already displayed, so nothing gets displayed — the
        // guard is what prevents this method from being used to peek at
        // codes the employee never paid an allowance for.
        $this->assertNull($component->get('totpCodePsn'));
        $this->assertNull($component->get('totpCodeEa'));
    }
}
