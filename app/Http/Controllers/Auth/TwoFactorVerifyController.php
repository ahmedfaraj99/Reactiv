<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorVerifyController
{
    public function show(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->google2fa_enabled) {
            return redirect()->route('2fa.setup.show');
        }

        if ($request->session()->has('google2fa.verified_at')) {
            return redirect()->intended('/admin');
        }

        return view('auth.two-factor-verify');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'one_time_password' => ['required', 'digits:6'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // 6-digit codes with a ±2×30s window accept 5 codes at a time —
        // without a throttle that's brute-forceable in minutes. Cap to
        // 5 wrong attempts per minute per user AND per IP; a determined
        // attacker rotating either dimension still hits the smaller limit.
        $limiterKey = 'two-factor:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            throw ValidationException::withMessages([
                'one_time_password' => "محاولات كثيرة — انتظر {$seconds} ثانية.",
            ]);
        }

        $secret = (string) $user->google2fa_secret;
        $code   = (string) $request->input('one_time_password');

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($secret, $code, 2);

        if (! $valid) {
            RateLimiter::hit($limiterKey, 60);
            return back()->withErrors([
                'one_time_password' => 'الرمز غير صحيح — تأكد أن الوقت على هاتفك مضبوط.',
            ]);
        }

        RateLimiter::clear($limiterKey);
        $request->session()->put('google2fa.verified_at', now()->timestamp);

        $user->forceFill([
            'last_login_ip' => $request->ip(),
            'last_login_at' => now(),
        ])->save();

        return redirect()->intended('/admin');
    }
}
