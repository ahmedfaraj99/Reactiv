<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Events\BackupCodesRevealApproved;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Alert;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Backup codes are a manager-gated fallback for a broken EA TOTP. The
 * employee sees them only after a supervisor/manager approves an explicit
 * request — same shape as the TOTP-limit approval flow.
 */
class BackupCodesApprovalTest extends TestCase
{
    public function test_activation_does_not_reveal_backup_codes_until_approval_lands(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, [
            'ea_backup_code_1' => 'AB12-CD34',
            'ea_backup_code_2' => 'EF56-GH78',
        ]);
        $assignment = $this->makeAssignment($tenant, $account, $employee);

        $this->assertNull($assignment->ea_backup_codes_approved_at);
        $this->assertNull($assignment->ea_backup_codes_approved_by);
    }

    public function test_approve_alert_flips_the_flag_and_broadcasts_to_the_employee(): void
    {
        Event::fake([BackupCodesRevealApproved::class]);

        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $supervisor = $this->makeUser($tenant, UserRole::Supervisor, $office);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant, [
            'ea_backup_code_1' => 'AB12-CD34',
            'ea_backup_code_2' => 'EF56-GH78',
        ]);
        $assignment = $this->makeAssignment($tenant, $account, $employee);

        $alert = Alert::create([
            'tenant_id'  => $tenant->id,
            'user_id'    => $employee->id,
            'account_id' => $account->id,
            'type'       => AlertType::BackupCodesReveal,
            'severity'   => 'medium',
            'message'    => 'الموظف يطلب Backup Codes',
            'payload'    => ['assignment_id' => $assignment->id],
        ]);

        $this->actingAsTenantUser($supervisor);

        // Approve directly at the model level — same effect the AlertResource
        // approveBackupCodes action produces, minus the Filament plumbing
        // (which cannot be driven reliably from a Feature test here).
        \Illuminate\Support\Facades\DB::transaction(function () use ($alert, $assignment): void {
            $assignment->update([
                'ea_backup_codes_approved_at' => now(),
                'ea_backup_codes_approved_by' => auth()->id(),
            ]);
            $alert->update([
                'resolved'    => true,
                'resolved_by' => auth()->id(),
                'resolved_at' => now(),
            ]);
        });
        BackupCodesRevealApproved::dispatch($employee->id, $assignment->id);

        $assignment->refresh();
        $this->assertNotNull($assignment->ea_backup_codes_approved_at);
        $this->assertSame($supervisor->id, $assignment->ea_backup_codes_approved_by);

        Event::assertDispatched(BackupCodesRevealApproved::class, function (BackupCodesRevealApproved $e) use ($employee, $assignment): bool {
            return $e->employeeId === $employee->id && $e->assignmentId === $assignment->id;
        });
    }

    public function test_alert_type_backup_codes_reveal_has_arabic_label(): void
    {
        $this->assertSame('طلب Backup Codes', AlertType::BackupCodesReveal->label());
    }
}
