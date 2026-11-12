<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * An iPhone pasting the one-time code auto-submits the 2FA form and the
 * employee then taps the button too. The second POST arrives with the
 * intended URL already consumed, and used to fall back to '/admin' —
 * the super-admin panel — which 403'd every tenant user.
 */
class TwoFactorRedirectTest extends TestCase
{
    public function test_a_double_submitted_code_still_lands_an_employee_in_the_app_panel(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $tenant = $this->makeTenant();
        $employee = $this->makeUser($tenant, UserRole::Employee, null, ['google2fa_secret' => $secret]);

        $this->actingAs($employee)->withSession(['url.intended' => url('/app')]);

        $code = $google2fa->getCurrentOtp($secret);

        $this->post('/2fa/verify', ['one_time_password' => $code])->assertRedirect(url('/app'));
        $this->post('/2fa/verify', ['one_time_password' => $code])->assertRedirect('/app');
        $this->get('/2fa/verify')->assertRedirect('/app');
    }
}
