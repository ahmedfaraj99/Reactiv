<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On every authenticated request, capture the client-provided fingerprint
 * cookie and keep the user's currently-seen fingerprint fresh so it stays
 * available on downstream reveal_logs for audit correlation.
 *
 * NewDevice alerts were removed after they proved to be mostly false
 * positives: `fc_fp` mixes in `navigator.userAgent` and screen size, so
 * Chrome auto-updates and monitor changes silently rotate the fingerprint
 * and paged the owner on every legitimate re-login. The fingerprint is
 * still recorded — it just no longer raises an alert on rotation.
 */
class BindDeviceFingerprint
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ?User $user */
        $user = $request->user();
        $rawFp = trim((string) $request->cookie('fc_fp', ''));

        if ($user === null || $rawFp === '' || $user->tenant_id === null) {
            return $next($request);
        }

        // Never store the raw client-supplied value. HMAC with the app key
        // so the DB column can't be lifted and replayed against a different
        // install, and so an attacker inspecting the DB can't sanity-check
        // what they'd need to spoof.
        $fp = hash_hmac('sha256', $rawFp, config('app.key'));

        // Silently keep device_fingerprint current. First bind, and every
        // subsequent rotation, is a no-op UX-wise but keeps downstream
        // audit rows (reveal_logs.device_fingerprint) pointing at whatever
        // the user actually looks like right now.
        if ($user->device_fingerprint !== $fp) {
            $user->forceFill(['device_fingerprint' => $fp])->save();
        }

        return $next($request);
    }
}
