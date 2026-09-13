<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\App\Pages\SessionApprovals;
use App\Models\SessionRequest;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One-click approval of every pending session request in the scope of
 * the current supervisor/manager. Supervisor scope must stay their
 * office only — never touch a pending request from another office in
 * the same tenant, even during "approve all".
 */
class SessionApprovalsBatchTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfig = [
            'fc27ac.session_approval.enabled'        => config('fc27ac.session_approval.enabled'),
            'fc27ac.session_approval.duration_hours' => config('fc27ac.session_approval.duration_hours'),
        ];
        config([
            'fc27ac.session_approval.enabled'        => true,
            'fc27ac.session_approval.duration_hours' => 4,
        ]);
    }

    protected function tearDown(): void
    {
        config($this->originalConfig);
        parent::tearDown();
    }

    private function pendingRequestFor(int $tenantId, int $userId, int $officeId): SessionRequest
    {
        return SessionRequest::create([
            'tenant_id'    => $tenantId,
            'user_id'      => $userId,
            'office_id'    => $officeId,
            'status'       => SessionRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);
    }

    public function test_owner_can_approve_every_pending_request_across_all_offices_in_one_click(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);
        $officeA = $this->makeOffice($tenant, ['name' => 'A']);
        $officeB = $this->makeOffice($tenant, ['name' => 'B']);
        $emp1 = $this->makeUser($tenant, UserRole::Employee, $officeA);
        $emp2 = $this->makeUser($tenant, UserRole::Employee, $officeA);
        $emp3 = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $r1 = $this->pendingRequestFor($tenant->id, $emp1->id, $officeA->id);
        $r2 = $this->pendingRequestFor($tenant->id, $emp2->id, $officeA->id);
        $r3 = $this->pendingRequestFor($tenant->id, $emp3->id, $officeB->id);

        $this->actingAsTenantUser($owner);

        Livewire::test(SessionApprovals::class)->callTableAction('approveAllPending');

        $this->assertSame(SessionRequest::STATUS_APPROVED, $r1->fresh()->status);
        $this->assertSame(SessionRequest::STATUS_APPROVED, $r2->fresh()->status);
        $this->assertSame(SessionRequest::STATUS_APPROVED, $r3->fresh()->status);
        $this->assertNotNull($r1->fresh()->expires_at);
    }

    public function test_manager_bulk_approve_never_leaks_across_unmanaged_offices(): void
    {
        $tenant = $this->makeTenant();
        $officeA = $this->makeOffice($tenant, ['name' => 'A']);
        $officeB = $this->makeOffice($tenant, ['name' => 'B']);
        $managerA = $this->makeUser($tenant, UserRole::Manager, $officeA);
        $officeA->update(['manager_id' => $managerA->id]);
        // officeB has its own manager (or none) — either way, managerA
        // does not manage it and must not see or touch its requests.
        $empInA = $this->makeUser($tenant, UserRole::Employee, $officeA);
        $empInB = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $inScope  = $this->pendingRequestFor($tenant->id, $empInA->id, $officeA->id);
        $outScope = $this->pendingRequestFor($tenant->id, $empInB->id, $officeB->id);

        $this->actingAsTenantUser($managerA);

        Livewire::test(SessionApprovals::class)->callTableAction('approveAllPending');

        $this->assertSame(SessionRequest::STATUS_APPROVED, $inScope->fresh()->status);
        $this->assertSame(SessionRequest::STATUS_PENDING, $outScope->fresh()->status);
    }

    public function test_manager_cannot_see_or_decide_on_another_managers_office(): void
    {
        $tenant = $this->makeTenant();
        $officeA = $this->makeOffice($tenant, ['name' => 'A']);
        $officeB = $this->makeOffice($tenant, ['name' => 'B']);
        $managerA = $this->makeUser($tenant, UserRole::Manager, $officeA);
        $managerB = $this->makeUser($tenant, UserRole::Manager, $officeB);
        $officeA->update(['manager_id' => $managerA->id]);
        $officeB->update(['manager_id' => $managerB->id]);
        $empInB = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $foreignRequest = $this->pendingRequestFor($tenant->id, $empInB->id, $officeB->id);

        $this->actingAsTenantUser($managerA);

        // The row from officeB must not appear in managerA's table.
        Livewire::test(SessionApprovals::class)
            ->assertCanNotSeeTableRecords([$foreignRequest]);

        // And even if a forged row id reaches the action handler, the
        // record must remain pending — the scoped query would not resolve
        // it, and assertCanDecide would 403 if the model somehow did.
        Livewire::test(SessionApprovals::class)
            ->callTableAction('approve', $foreignRequest);

        $this->assertSame(SessionRequest::STATUS_PENDING, $foreignRequest->fresh()->status);
    }

    public function test_supervisor_bulk_approve_never_leaks_across_offices(): void
    {
        $tenant = $this->makeTenant();
        $officeA = $this->makeOffice($tenant, ['name' => 'A']);
        $officeB = $this->makeOffice($tenant, ['name' => 'B']);
        $supervisorA = $this->makeUser($tenant, UserRole::Supervisor, $officeA);
        $empInA  = $this->makeUser($tenant, UserRole::Employee, $officeA);
        $empInB  = $this->makeUser($tenant, UserRole::Employee, $officeB);

        $inScope   = $this->pendingRequestFor($tenant->id, $empInA->id, $officeA->id);
        $outScope  = $this->pendingRequestFor($tenant->id, $empInB->id, $officeB->id);

        $this->actingAsTenantUser($supervisorA);

        Livewire::test(SessionApprovals::class)->callTableAction('approveAllPending');

        // Own office → approved.
        $this->assertSame(SessionRequest::STATUS_APPROVED, $inScope->fresh()->status);
        // Other office → untouched.
        $this->assertSame(SessionRequest::STATUS_PENDING, $outScope->fresh()->status);
    }
}
