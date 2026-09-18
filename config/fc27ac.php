<?php

declare(strict_types=1);

/**
 * FC27AC application policies. Later these will be per-tenant overrides
 * loaded from the tenants table; today they are global defaults.
 */
return [

    // Seconds the credentials stay visible before auto-hiding.
    'credentials_reveal_seconds' => (int) env('FC27AC_REVEAL_SECONDS', 30),

    // Seconds a generated TOTP code stays visible after the employee
    // presses "generate". Kept below the ±1 window (~60s) that PSN/EA
    // TOTP verifiers accept, so the code shown remains usable on the
    // console for its whole visible lifetime regardless of where in
    // the 30s TOTP window the press landed. See TotpService.
    'totp_display_seconds' => (int) env('FC27AC_TOTP_DISPLAY_SECONDS', 45),

    // Idle session lifetime in minutes (mirrors SESSION_LIFETIME).
    'session_idle_minutes' => (int) env('SESSION_LIFETIME', 15),

    // Rate limits on sensitive actions (reveal_credentials, generate_totp_*).
    // Buckets are per-employee (hour + day) and per-IP (hour, all actions
    // combined). Hitting a bucket blocks the action AND fires a
    // Alert::TYPE_HIGH_VOLUME (deduped once per hour per user).
    'reveal_rate_limits' => [
        'reveal' => [
            'per_hour' => (int) env('FC27AC_REVEAL_PER_HOUR', 30),
            'per_day'  => (int) env('FC27AC_REVEAL_PER_DAY', 150),
        ],
        'totp' => [
            'per_hour' => (int) env('FC27AC_TOTP_PER_HOUR', 20),
            'per_day'  => (int) env('FC27AC_TOTP_PER_DAY', 80),
        ],
        'ip_per_hour' => (int) env('FC27AC_SENSITIVE_IP_PER_HOUR', 120),
    ],

    // Where weekly office PDFs are written (relative to storage/app disk).
    'weekly_report_path' => env('FC27AC_WEEKLY_REPORT_PATH', 'reports/weekly'),

    // Just-in-time session approval for employees. When enabled, every
    // employee login is blocked until a manager or supervisor from the
    // same office approves the resulting SessionRequest. Approvals last
    // `duration_hours`, then the employee is bounced back to the
    // waiting screen until re-approved.
    'session_approval' => [
        'enabled'         => (bool) env('FC27AC_SESSION_APPROVAL', true),
        'duration_hours'  => (int)  env('FC27AC_SESSION_APPROVAL_HOURS', 24),
    ],
];
