<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\RevealLog;
use Tests\TestCase;
use App\Enums\AlertType;

/**
 * The three detection rules AlertEngine runs on every RevealLog write.
 * RevealLog is observed (RevealLogObserver) and dispatches
 * EvaluateAlertRules on create() — with QUEUE_CONNECTION=sync in
 * tests, that runs synchronously, so simply creating the log rows
 * below exercises the real automatic pipeline end to end rather than
 * calling AlertEngine by hand.
 */
class AlertEngineTest extends TestCase
{
    private function makeLog(int $tenantId, int $userId, int $accountId, string $action, \DateTimeInterface $at): RevealLog
    {
        return RevealLog::create([
            'tenant_id'  => $tenantId,
            'user_id'    => $userId,
            'account_id' => $accountId,
            'action'     => $action,
            'created_at' => $at,
        ]);
    }

    // ── repeatRevealRule ─────────────────────────────────────────────

    public function test_a_single_reveal_today_raises_no_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', now());

        $this->assertSame(0, Alert::where('type', AlertType::RepeatReveal)->count());
    }

    public function test_a_second_reveal_of_the_same_account_today_raises_repeat_reveal(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', now()->subHour());
        $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', now());

        $alert = Alert::where('type', AlertType::RepeatReveal)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AlertSeverity::High, $alert->severity);
        $this->assertSame($employee->id, $alert->user_id);
    }

    public function test_a_reveal_of_a_different_account_does_not_count_toward_repeat(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $accountA = $this->makeAccount($tenant, ['email' => 'a@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('a@example.com')]);
        $accountB = $this->makeAccount($tenant, ['email' => 'b@example.com', 'email_fingerprint' => \App\Models\Account::fingerprint('b@example.com')]);

        $this->makeLog($tenant->id, $employee->id, $accountA->id, 'reveal_credentials', now()->subHour());
        $this->makeLog($tenant->id, $employee->id, $accountB->id, 'reveal_credentials', now());

        $this->assertSame(0, Alert::where('type', AlertType::RepeatReveal)->count());
    }

    public function test_a_reveal_yesterday_does_not_count_toward_todays_repeat(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', now()->subDay());
        $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', now());

        $this->assertSame(0, Alert::where('type', AlertType::RepeatReveal)->count());
    }

    // ── offHoursRule ─────────────────────────────────────────────────

    public function test_activity_inside_working_hours_raises_no_off_hours_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', now()->setTime(14, 0));

        $this->assertSame(0, Alert::where('type', AlertType::OffHours)->count());
    }

    public function test_activity_at_2am_raises_off_hours_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $this->makeLog($tenant->id, $employee->id, $account->id, 'generate_totp_psn', now()->setTime(2, 0));

        $alert = Alert::where('type', AlertType::OffHours)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AlertSeverity::Medium, $alert->severity);
    }

    public function test_activity_exactly_at_work_start_boundary_is_in_hours(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        // WORK_START = 8 — the boundary hour itself must count as inside.
        $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', now()->setTime(8, 0));

        $this->assertSame(0, Alert::where('type', AlertType::OffHours)->count());
    }

    public function test_activity_exactly_at_work_end_boundary_is_out_of_hours(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        // WORK_END = 22 — the boundary hour itself must NOT count as inside
        // (the check is `< WORK_END`, so 22:00 sharp is already outside).
        $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', now()->setTime(22, 0));

        $this->assertSame(1, Alert::where('type', AlertType::OffHours)->count());
    }

    public function test_off_hours_rule_ignores_non_sensitive_actions(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $this->makeLog($tenant->id, $employee->id, $account->id, 'submit_proof', now()->setTime(2, 0));

        $this->assertSame(0, Alert::where('type', AlertType::OffHours)->count());
    }

    // ── highVolumeRule ───────────────────────────────────────────────

    public function test_39_actions_in_an_hour_does_not_trigger_high_volume(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $now = now();
        for ($i = 0; $i < 39; $i++) {
            $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', $now->copy()->subMinutes($i));
        }

        $this->assertSame(0, Alert::where('type', AlertType::HighVolume)->count());
    }

    public function test_40th_action_in_an_hour_triggers_high_volume(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $now = now();
        for ($i = 1; $i <= 40; $i++) {
            $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', $now->copy()->subMinutes(40 - $i));
        }

        $alert = Alert::where('type', AlertType::HighVolume)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame(1, Alert::where('type', AlertType::HighVolume)->count());
    }

    public function test_high_volume_alert_is_debounced_within_the_same_hour(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $now = now();
        for ($i = 1; $i <= 41; $i++) {
            $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', $now->copy()->subMinutes(41 - $i));
        }

        // 41 actions crossed the threshold twice (at #40 and #41), but the
        // second must be suppressed — an alert already fired in the window.
        $this->assertSame(1, Alert::where('type', AlertType::HighVolume)->count());
    }

    public function test_actions_older_than_an_hour_drop_out_of_the_high_volume_window(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $now = now();
        // 39 rows over an hour ago — outside the window — plus this one.
        for ($i = 1; $i <= 39; $i++) {
            $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', $now->copy()->subHours(2)->subMinutes($i));
        }
        $this->makeLog($tenant->id, $employee->id, $account->id, 'reveal_credentials', $now);

        $this->assertSame(0, Alert::where('type', AlertType::HighVolume)->count());
    }
}
