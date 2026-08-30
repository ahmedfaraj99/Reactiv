<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\UserRole;
use App\Filament\App\Pages\Activation;
use App\Models\AccountAssignment;
use App\Models\Alert;
use Tests\TestCase;
use App\Enums\AlertType;

/**
 * When an employee submits proof faster than physically possible for the
 * number of matches the account requires (see
 * AccountAssignment::isSuspiciouslyFast), a critical alert fires so the
 * supervisor knows to look twice. Not a hard block: false positives are
 * possible on very fast/simple activations, so the reviewer stays the
 * decision-maker. Threshold is deliberately generous (30s base + 90s
 * per match) — the target is "submitted an old screenshot without
 * playing", not "worked efficiently".
 */
class SuspiciousSpeedAlertTest extends TestCase
{
    private function activationFor(AccountAssignment $assignment): Activation
    {
        $page = new Activation();
        $page->assignment = $assignment;
        return $page;
    }

    public function test_a_matches_activation_completed_in_20_seconds_fires_a_critical_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['matches_required' => 3]);
        $now = now();
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'                  => AccountAssignment::STATUS_AWAITING_REVIEW,
            'credentials_revealed_at' => $now->copy()->subSeconds(20),
            'submitted_at'            => $now,
        ]);

        $this->activationFor($assignment)->flagIfSuspiciouslyFast();

        $alert = Alert::where('type', AlertType::SuspiciousSpeed)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame(3, $alert->payload['matches_required']);
        $this->assertLessThan(30, $alert->payload['seconds']);
    }

    public function test_a_matches_activation_that_took_at_least_the_minimum_expected_time_does_not_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['matches_required' => 3]);
        // Threshold for 3 matches = 30 + 3 × 90 = 300 seconds. 15 minutes is safely above.
        $now = now();
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'                  => AccountAssignment::STATUS_AWAITING_REVIEW,
            'credentials_revealed_at' => $now->copy()->subMinutes(15),
            'submitted_at'            => $now,
        ]);

        $this->activationFor($assignment)->flagIfSuspiciouslyFast();

        $this->assertSame(0, Alert::where('type', AlertType::SuspiciousSpeed)->count());
    }

    public function test_activation_only_accounts_use_the_lower_30_second_threshold(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['matches_required' => 0]);
        // Under 30 seconds — activation-only should still catch a 5-second submission.
        $now = now();
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'                  => AccountAssignment::STATUS_AWAITING_REVIEW,
            'credentials_revealed_at' => $now->copy()->subSeconds(5),
            'submitted_at'            => $now,
        ]);

        $this->activationFor($assignment)->flagIfSuspiciouslyFast();

        $this->assertSame(1, Alert::where('type', AlertType::SuspiciousSpeed)->count());
    }

    public function test_missing_timing_data_never_fires_a_false_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, ['matches_required' => 3]);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_AWAITING_REVIEW,
            // No credentials_revealed_at — an older row from before the
            // column existed. Can't compute a duration → must not alert
            // rather than firing on the wrong basis.
            'submitted_at' => now(),
        ]);

        $this->activationFor($assignment)->flagIfSuspiciouslyFast();

        $this->assertSame(0, Alert::where('type', AlertType::SuspiciousSpeed)->count());
    }
}
