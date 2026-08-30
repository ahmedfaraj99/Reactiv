<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Alert;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\AlertType;

/**
 * On every authenticated request, capture the client-provided fingerprint
 * cookie. First seen fingerprint is bound to the user. Any later request
 * from a different fingerprint fires a new_device alert. This is a
 * deterrent, not a lock — a determined user can clear cookies. Combined
 * with reveal_logs it makes device sharing obvious after the fact.
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
        // what they'd need to spoof. The cookie itself is still forgeable
        // (that's inherent to a client-side fingerprint) but at least the
        // server-side representation is bound to this deployment.
        $fp = hash_hmac('sha256', $rawFp, config('app.key'));

        // First fingerprint ever → bind silently
        if (empty($user->device_fingerprint)) {
            $user->forceFill(['device_fingerprint' => $fp])->save();
            return $next($request);
        }

        if ($user->device_fingerprint === $fp) {
            return $next($request);
        }

        // Mismatch → fire alert, but only once per hour to avoid spam
        $recent = Alert::query()
            ->where('user_id', $user->id)
            ->where('type', AlertType::NewDevice)
            ->where('created_at', '>=', now()->subHour())
            ->exists();

        if (! $recent) {
            Alert::create([
                'tenant_id' => $user->tenant_id,
                'user_id'   => $user->id,
                'type'      => AlertType::NewDevice,
                'severity'  => 'high',
                'message'   => 'تسجيل دخول من جهاز مختلف عن المسجَّل سابقاً',
                'payload'   => [
                    'expected_fp' => substr($user->device_fingerprint, 0, 12).'…',
                    'seen_fp'     => substr($fp, 0, 12).'…',
                    'ip'          => $request->ip(),
                ],
            ]);
        }

        return $next($request);
    }
}
