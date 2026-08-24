<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sliding-window guard for the sensitive credential actions
 * (reveal_credentials, generate_totp_*). Complements the per-assignment
 * TOTP allowance which caps a single account; this caps how often ONE
 * employee (or one IP) can trigger these actions across ALL accounts,
 * catching a compromised account being farmed.
 *
 * On breach: block the action AND raise a Alert::TYPE_HIGH_VOLUME
 * (deduped to once per hour per user, otherwise a locked-out employee
 * mashing the button would flood the alerts inbox).
 */
class RevealRateLimiter
{
    /**
     * Returns null when the action may proceed, or an Arabic reason
     * string suitable to show in a Filament danger notification.
     */
    public function check(User $user, string $ip, string $action): ?string
    {
        $limits = (array) config('fc27ac.reveal_rate_limits');
        $bucket = str_starts_with($action, 'generate_totp') ? 'totp' : 'reveal';

        $perHour = (int) ($limits[$bucket]['per_hour'] ?? 30);
        $perDay  = (int) ($limits[$bucket]['per_day']  ?? 150);
        $ipHour  = (int) ($limits['ip_per_hour']       ?? 120);

        $hourlyKey = "rl:{$bucket}:hour:user:{$user->id}";
        $dailyKey  = "rl:{$bucket}:day:user:{$user->id}";
        // Hash the IP so IPv6 or unusual chars can't break the cache key.
        $ipKey     = 'rl:any:hour:ip:'.sha1($ip);

        if (RateLimiter::tooManyAttempts($hourlyKey, $perHour)) {
            return "تجاوزت الحد المسموح لهذه الساعة ({$perHour}). حاول بعد ".
                RateLimiter::availableIn($hourlyKey).' ثانية.';
        }
        if (RateLimiter::tooManyAttempts($dailyKey, $perDay)) {
            return "تجاوزت الحد اليومي المسموح ({$perDay}).";
        }
        if (RateLimiter::tooManyAttempts($ipKey, $ipHour)) {
            return "تجاوز عنوان الشبكة الحد المسموح لهذه الساعة.";
        }

        // Hit AFTER all checks pass, otherwise a blocked attempt still
        // pushes the counter up and delays recovery.
        RateLimiter::hit($hourlyKey, 3600);
        RateLimiter::hit($dailyKey, 86_400);
        RateLimiter::hit($ipKey, 3600);

        return null;
    }

    public function raiseAlert(User $user, string $action, string $reason, ?int $accountId = null): void
    {
        $recent = Alert::query()
            ->where('user_id', $user->id)
            ->where('type', Alert::TYPE_HIGH_VOLUME)
            ->where('created_at', '>=', now()->subHour())
            ->exists();

        if ($recent) {
            return;
        }

        Alert::create([
            'tenant_id'  => $user->tenant_id,
            'user_id'    => $user->id,
            'account_id' => $accountId,
            'type'       => Alert::TYPE_HIGH_VOLUME,
            'severity'   => 'high',
            'message'    => 'موظف تجاوز حد العمليات الحساسة: '.$reason,
            'payload'    => ['action' => $action, 'reason' => $reason],
        ]);
    }
}
