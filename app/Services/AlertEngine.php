<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Alert;
use App\Models\RevealLog;

/**
 * Rule-based alert generator, evaluated once per RevealLog write. Rules
 * are intentionally simple SQL counts so this stays fast even at 5K+
 * reveals per day per tenant. Add rules by extending evaluate().
 */
class AlertEngine
{
    /** Working hours enforced (24h clock, tenant-local time is assumed). */
    private const WORK_START = 8;
    private const WORK_END   = 22;

    /** More than this many reveals in an hour by a single user → high_volume. */
    private const HIGH_VOLUME_PER_HOUR = 40;

    public function evaluate(RevealLog $log): void
    {
        $this->repeatRevealRule($log);
        $this->offHoursRule($log);
        $this->highVolumeRule($log);
    }

    /**
     * Same user revealed the SAME account credentials more than once.
     * Once is expected (one activation = one reveal); a second time
     * within the same day is suspicious.
     */
    private function repeatRevealRule(RevealLog $log): void
    {
        if ($log->action !== 'reveal_credentials') {
            return;
        }

        $count = RevealLog::query()
            ->where('user_id', $log->user_id)
            ->where('account_id', $log->account_id)
            ->where('action', 'reveal_credentials')
            ->whereDate('created_at', $log->created_at->toDateString())
            ->count();

        if ($count <= 1) {
            return;
        }

        Alert::create([
            'tenant_id'  => $log->tenant_id,
            'user_id'    => $log->user_id,
            'account_id' => $log->account_id,
            'type'       => Alert::TYPE_REPEAT_REVEAL,
            'severity'   => 'high',
            'message'    => "الموظف كشف بيانات نفس الحساب {$count} مرات اليوم",
            'payload'    => ['count' => $count],
        ]);
    }

    /**
     * Any sensitive action outside configured working hours.
     */
    private function offHoursRule(RevealLog $log): void
    {
        if (! in_array($log->action, ['reveal_credentials', 'generate_totp_psn', 'generate_totp_ea'], true)) {
            return;
        }

        $hour = (int) $log->created_at->format('G');
        if ($hour >= self::WORK_START && $hour < self::WORK_END) {
            return;
        }

        Alert::create([
            'tenant_id'  => $log->tenant_id,
            'user_id'    => $log->user_id,
            'account_id' => $log->account_id,
            'type'       => Alert::TYPE_OFF_HOURS,
            'severity'   => 'medium',
            'message'    => "نشاط خارج ساعات العمل الرسمية ({$hour}:00)",
            'payload'    => ['hour' => $hour],
        ]);
    }

    /**
     * Same user generating a huge burst of reveals/TOTPs in the past hour.
     */
    private function highVolumeRule(RevealLog $log): void
    {
        if (! in_array($log->action, ['reveal_credentials', 'generate_totp_psn', 'generate_totp_ea'], true)) {
            return;
        }

        $count = RevealLog::query()
            ->where('user_id', $log->user_id)
            ->whereIn('action', ['reveal_credentials', 'generate_totp_psn', 'generate_totp_ea'])
            ->where('created_at', '>=', $log->created_at->copy()->subHour())
            ->count();

        if ($count < self::HIGH_VOLUME_PER_HOUR) {
            return;
        }

        // Debounce: don't spam alerts if we already fired one in the last hour.
        $recent = Alert::query()
            ->where('user_id', $log->user_id)
            ->where('type', Alert::TYPE_HIGH_VOLUME)
            ->where('created_at', '>=', $log->created_at->copy()->subHour())
            ->exists();
        if ($recent) {
            return;
        }

        Alert::create([
            'tenant_id'  => $log->tenant_id,
            'user_id'    => $log->user_id,
            'type'       => Alert::TYPE_HIGH_VOLUME,
            'severity'   => 'critical',
            'message'    => "{$count} عملية كشف/توليد خلال الساعة الأخيرة",
            'payload'    => ['count' => $count],
        ]);
    }
}
