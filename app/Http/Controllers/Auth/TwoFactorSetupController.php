<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorSetupController
{
    public function show(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->google2fa_enabled) {
            return redirect()->intended();
        }

        // Generate a pending secret once per session so refreshing keeps the same QR
        if (! Session::has('2fa_pending_secret')) {
            $google2fa = new Google2FA();
            Session::put('2fa_pending_secret', $google2fa->generateSecretKey());
        }

        $secret = (string) Session::get('2fa_pending_secret');

        $qrUri = (new Google2FA())->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret,
        );

        $qrSvg = $this->renderSvg($qrUri);

        return view('auth.two-factor-setup', [
            'secret' => $secret,
            'qrSvg'  => $qrSvg,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'one_time_password' => ['required', 'digits:6'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $secret = (string) Session::get('2fa_pending_secret', '');
        if ($secret === '') {
            return redirect()->route('2fa.setup.show')
                ->withErrors(['one_time_password' => 'انتهت الجلسة، حاول مرة أخرى.']);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($secret, (string) $request->input('one_time_password'), 1);

        if (! $valid) {
            return back()->withErrors([
                'one_time_password' => 'الرمز غير صحيح — تأكد أن الوقت على هاتفك مضبوط.',
            ]);
        }

        $user->forceFill([
            'google2fa_secret'  => $secret,
            'google2fa_enabled' => true,
        ])->save();

        Session::forget('2fa_pending_secret');
        Session::put('google2fa.verified_at', now()->timestamp);

        return redirect()->intended('/admin');
    }

    private function renderSvg(string $uri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220, 1),
            new SvgImageBackEnd(),
        );

        return (new Writer($renderer))->writeString($uri);
    }
}
