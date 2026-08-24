<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects any authenticated user who has not yet enabled TOTP 2FA
 * to the setup page. This is enforced on every protected route so a
 * user cannot access the application until 2FA is enrolled.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ?User $user */
        $user = $request->user();

        if ($user !== null && ! $user->google2fa_enabled && ! $this->isSetupRoute($request)) {
            return redirect()->route('2fa.setup.show');
        }

        return $next($request);
    }

    private function isSetupRoute(Request $request): bool
    {
        return $request->routeIs('2fa.setup.*') || $request->routeIs('logout');
    }
}
