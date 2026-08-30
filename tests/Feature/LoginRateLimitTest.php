<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\UserRole;
use App\Filament\Auth\UnifiedLogin;
use App\Models\Alert;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;
use App\Enums\AlertType;

/**
 * 3 failed attempts within 120 seconds from the same IP trips the
 * limiter (UnifiedLogin::authenticate(), see rateLimit(3, 120)) — the
 * 4th attempt doesn't even reach credential checking, and raises a
 * TYPE_LOGIN_ATTACK alert on the target user's tenant so the owner
 * sees it.
 */
class LoginRateLimitTest extends TestCase
{
    private function mountLogin()
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(UnifiedLogin::class);
    }

    public function test_three_failed_attempts_do_not_trip_the_limiter(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, UserRole::TenantOwner, null, [
            'email'    => 'owner@rate-limit-test.local',
            'password' => Hash::make('CorrectPassword!1'),
        ]);

        $login = $this->mountLogin();

        for ($i = 0; $i < 3; $i++) {
            $login->set('data.email', $user->email)
                ->set('data.password', 'WrongPassword')
                ->call('authenticate');
        }

        $this->assertSame(0, Alert::where('type', AlertType::LoginAttack)->count());
        $this->assertGuest();
    }

    public function test_fourth_attempt_within_the_window_trips_the_limiter_and_raises_an_alert(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, UserRole::TenantOwner, null, [
            'email'    => 'owner2@rate-limit-test.local',
            'password' => Hash::make('CorrectPassword!1'),
        ]);

        $login = $this->mountLogin();

        for ($i = 0; $i < 3; $i++) {
            $login->set('data.email', $user->email)
                ->set('data.password', 'WrongPassword')
                ->call('authenticate');
        }

        // The 4th attempt — even with the CORRECT password — must be
        // blocked by the rate limiter before credentials are ever checked.
        $login->set('data.email', $user->email)
            ->set('data.password', 'CorrectPassword!1')
            ->call('authenticate');

        $alert = Alert::where('type', AlertType::LoginAttack)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame($tenant->id, $alert->tenant_id);
        $this->assertSame($user->id, $alert->user_id);
        $this->assertGuest();
    }

    public function test_rate_limit_on_an_unrecognized_email_does_not_crash_and_raises_no_alert(): void
    {
        $login = $this->mountLogin();

        for ($i = 0; $i < 4; $i++) {
            $login->set('data.email', 'nobody@nowhere.example')
                ->set('data.password', 'WhateverPassword')
                ->call('authenticate');
        }

        $this->assertSame(0, Alert::where('type', AlertType::LoginAttack)->count());
        $this->assertGuest();
    }
}
