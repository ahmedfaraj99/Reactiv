<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\EscalateOverdueAssignments;
use App\Models\Alert;
use App\Models\AccountAssignment;
use Tests\TestCase;
use App\Enums\AlertType;

class EscalateOverdueAssignmentsTest extends TestCase
{
    public function test_assignment_overdue_by_24_hours_raises_an_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee, [
            'status'      => AccountAssignment::STATUS_IN_PROGRESS,
            'assigned_at' => now()->subHours(25),
        ]);

        (new EscalateOverdueAssignments)->handle();

        $alert = Alert::where('type', AlertType::AssignmentOverdue)->first();
        $this->assertNotNull($alert);
        $this->assertSame($employee->id, $alert->user_id);
        $this->assertSame($account->id, $alert->account_id);
        $this->assertSame($assignment->id, $alert->payload['assignment_id']);
    }

    public function test_assignment_within_24_hours_does_not_raise_an_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $this->makeAssignment($tenant, $account, $employee, [
            'status'      => AccountAssignment::STATUS_IN_PROGRESS,
            'assigned_at' => now()->subHours(2),
        ]);

        (new EscalateOverdueAssignments)->handle();

        $this->assertSame(0, Alert::where('type', AlertType::AssignmentOverdue)->count());
    }

    public function test_completed_assignments_are_never_escalated(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $this->makeAssignment($tenant, $account, $employee, [
            'status'       => AccountAssignment::STATUS_COMPLETED,
            'assigned_at'  => now()->subHours(48),
            'completed_at' => now()->subHours(40),
        ]);

        (new EscalateOverdueAssignments)->handle();

        $this->assertSame(0, Alert::where('type', AlertType::AssignmentOverdue)->count());
    }

    public function test_a_second_sweep_does_not_duplicate_an_unresolved_alert(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $this->makeAssignment($tenant, $account, $employee, [
            'status'      => AccountAssignment::STATUS_IN_PROGRESS,
            'assigned_at' => now()->subHours(30),
        ]);

        (new EscalateOverdueAssignments)->handle();
        (new EscalateOverdueAssignments)->handle();

        $this->assertSame(1, Alert::where('type', AlertType::AssignmentOverdue)->count());
    }

    public function test_a_resolved_alert_allows_re_escalation_on_the_next_sweep(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $this->makeAssignment($tenant, $account, $employee, [
            'status'      => AccountAssignment::STATUS_IN_PROGRESS,
            'assigned_at' => now()->subHours(30),
        ]);

        (new EscalateOverdueAssignments)->handle();
        Alert::where('type', AlertType::AssignmentOverdue)->update(['resolved' => true]);
        (new EscalateOverdueAssignments)->handle();

        $this->assertSame(2, Alert::where('type', AlertType::AssignmentOverdue)->count());
    }
}
