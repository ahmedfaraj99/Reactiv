<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a user is authenticated AND has 2FA enrolled, this middleware
 * enforces that the current session has completed 2FA verification.
 * If not, it saves the intended URL and redirects to /2fa/verify.
 */
class RequireTwoFactorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ?User $user */
        $user = $request->user();

        if ($user === null || ! $user->google2fa_enabled) {
            return $next($request);
        }

        if ($request->session()->has('google2fa.verified_at')) {
            return $next($request);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('2fa.verify.show');
    }
}
