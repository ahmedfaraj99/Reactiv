<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\Alert;
use App\Models\SessionRequest;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UnifiedLogin extends Login
{
    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(3, 120);
        } catch (TooManyRequestsException $exception) {
            $this->reportLoginAttack();

            // Filament's default rate-limit notification is a toast that's
            // easy to miss and worded in English. Attach a specific,
            // Arabic error to the email field so the user sees *why* the
            // form failed — not the generic "invalid credentials" that
            // makes them assume the password is wrong and try again.
            throw ValidationException::withMessages([
                'data.email' => 'محاولات دخول كثيرة. انتظر '
                    .$exception->secondsUntilAvailable
                    .' ثانية قبل المحاولة مرة أخرى.',
            ]);
        }

        $data = $this->form->getState();

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            $this->throwFailureValidationException();
        }

        $user = Filament::auth()->user();

        if (! $user instanceof FilamentUser || ! $this->canAccessAnyPanel($user)) {
            Filament::auth()->logout();

            // Password matched (attempt() succeeded) but the user can't
            // enter any panel. Distinguish the "pending invitation" case
            // from generic access denial so the person knows what to do
            // instead of blaming the password.
            if ($user instanceof User && $user->email_verified_at === null) {
                throw ValidationException::withMessages([
                    'data.email' => 'حسابك لم يُفعَّل بعد. افتح رابط التفعيل في بريدك، أو اطلب من مديرك إعادة إرسال دعوة.',
                ]);
            }

            $this->throwFailureValidationException();
        }

        session()->regenerate();
        session()->put('url.intended', $this->getRedirectUrl());

        $this->openSessionRequestForEmployee($user);

        return app(LoginResponse::class);
    }

    /**
     * Every employee login opens a pending SessionRequest UNLESS they
     * already have one that's currently valid — logging out and back
     * in mid-shift shouldn't spam the supervisor with a second approval
     * for the same person on the same day. Managers, supervisors and
     * owners are trusted at the account level and don't need a per-
     * login approval — they're the ones granting approvals. Skipped
     * entirely when the feature is off (`session_approval.enabled`).
     */
    protected function openSessionRequestForEmployee(FilamentUser $user): void
    {
        if (! config('fc27ac.session_approval.enabled')) {
            return;
        }

        if (! $user instanceof User || ! $user->isEmployee() || $user->tenant_id === null) {
            return;
        }

        // Approval is user-scoped, not session-scoped: an unexpired
        // approval already lets them straight through the middleware, so
        // asking for a fresh one would create noise in the supervisor's
        // queue for no additional gate.
        if (SessionRequest::currentFor($user->id) !== null) {
            return;
        }

        SessionRequest::create([
            'tenant_id'          => $user->tenant_id,
            'user_id'            => $user->id,
            'office_id'          => $user->office_id,
            'status'             => SessionRequest::STATUS_PENDING,
            'requested_at'       => now(),
            'ip'                 => request()->ip(),
            'user_agent'         => substr((string) request()->userAgent(), 0, 500),
            'device_fingerprint' => request()->cookie('fc_fp'),
        ]);

        \App\Events\SessionRequestChanged::dispatch(
            (int) $user->tenant_id,
            (int) $user->office_id,
        );
    }

    protected function canAccessAnyPanel(FilamentUser $user): bool
    {
        foreach (Filament::getPanels() as $panel) {
            if ($user->canAccessPanel($panel)) {
                return true;
            }
        }

        return false;
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }

    /**
     * The rate limit tripped — 3 failed attempts in 2 minutes from the
     * same IP. If the attempted email belongs to a real user, raise an
     * alert on their tenant so the owner sees it. Otherwise (email
     * doesn't exist at all) there's no tenant to attach it to, so it
     * just goes to the application log for platform-level review.
     */
    protected function reportLoginAttack(): void
    {
        $email = (string) ($this->form->getState()['email'] ?? '');
        $ip = request()->ip();
        $target = User::where('email', $email)->first();

        if ($target === null || $target->tenant_id === null) {
            Log::warning('Login rate limit exceeded for unrecognized email', [
                'email' => $email,
                'ip'    => $ip,
            ]);
            return;
        }

        Alert::create([
            'tenant_id' => $target->tenant_id,
            'user_id'   => $target->id,
            'type'      => Alert::TYPE_LOGIN_ATTACK,
            'severity'  => 'critical',
            'message'   => "محاولات دخول فاشلة متكررة على حساب {$target->name} من عنوان {$ip}",
            'payload'   => ['ip' => $ip, 'email' => $email],
        ]);
    }

    protected function getRedirectUrl(): string
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return Filament::getPanel('admin')->getUrl();
        }

        return Filament::getPanel('app')->getUrl();
    }
}
