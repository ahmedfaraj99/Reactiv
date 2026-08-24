<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountAssignment;
use Tests\TestCase;

/**
 * The read-only timeline partial rendered from the review page. Covers
 * the two invariants: nulls are dropped so the timeline never fakes a
 * step that never happened, and the ordering + labels match the actual
 * lifecycle a supervisor expects to see.
 */
class AssignmentTimelineTest extends TestCase
{
    private function render(AccountAssignment $assignment): string
    {
        return view('filament.app.partials.assignment-timeline', ['assignment' => $assignment])->render();
    }

    public function test_timeline_shows_all_events_that_actually_happened(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        $t0 = now()->subMinutes(20);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'                  => AccountAssignment::STATUS_COMPLETED,
            'assigned_at'             => $t0,
            'started_at'              => $t0->copy()->addMinute(),
            'credentials_revealed_at' => $t0->copy()->addMinute()->addSeconds(10),
            'first_totp_at'           => $t0->copy()->addMinutes(3),
            'submitted_at'            => $t0->copy()->addMinutes(12),
            'reviewed_at'             => $t0->copy()->addMinutes(15),
            'completed_at'            => $t0->copy()->addMinutes(15),
        ]);

        $html = $this->render($assignment);

        foreach (['مُخصَّص', 'بدأ التفعيل', 'كُشفت البيانات', 'وُلِّد كود TOTP', 'أُرسل الإثبات', 'راجعه المشرف', 'اكتمل'] as $label) {
            $this->assertStringContainsString($label, $html, "expected label '{$label}' in timeline");
        }
        // Delta between reveal and first TOTP is ~1min 50s → rounded 2 minutes.
        $this->assertStringContainsString('بعد 2 دقيقة', $html);
    }

    public function test_timeline_hides_events_that_never_happened(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);

        // An assignment stuck between reveal and TOTP: no first_totp_at,
        // no submitted_at. Timeline must not fabricate the missing rows.
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'                  => AccountAssignment::STATUS_IN_PROGRESS,
            'started_at'              => now()->subMinutes(5),
            'credentials_revealed_at' => now()->subMinutes(4),
        ]);

        $html = $this->render($assignment);

        $this->assertStringContainsString('كُشفت البيانات', $html);
        $this->assertStringNotContainsString('وُلِّد كود TOTP', $html);
        $this->assertStringNotContainsString('أُرسل الإثبات', $html);
        $this->assertStringNotContainsString('اكتمل', $html);
    }
}
