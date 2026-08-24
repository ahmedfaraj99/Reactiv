<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Auth\UnifiedLogin;
use App\Models\SessionRequest;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Logging in and out mid-shift and back in shouldn't spam the
 * supervisor with duplicate approval requests — an approval is
 * user-scoped and lets the employee straight through the middleware
 * for its full window, so a fresh pending row for the same person is
 * pure noise.
 */
class SessionRequestReuseTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Snapshot then override — config() writes bleed across tests
        // in the same process (Laravel doesn't reset it between them),
        // so tearDown must put the previous values back or later suites
        // like EnforceSingleActiveAccountTest inherit the override and
        // fail with redirect loops for unapproved employees.
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

    private function login(string $email, string $password): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(UnifiedLogin::class)
            ->set('data.email', $email)
            ->set('data.password', $password)
            ->call('authenticate');
    }

    public function test_a_second_login_while_an_approval_is_still_valid_does_not_create_another_request(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office, [
            'email'    => 'emp-reuse@local',
            'password' => Hash::make('Password!123'),
        ]);

        // Pre-seed an already-approved, unexpired session — as if the
        // supervisor approved this employee 20 minutes ago.
        SessionRequest::create([
            'tenant_id'    => $tenant->id,
            'user_id'      => $employee->id,
            'office_id'    => $office->id,
            'status'       => SessionRequest::STATUS_APPROVED,
            'requested_at' => now()->subMinutes(20),
            'decided_at'   => now()->subMinutes(19),
            'expires_at'   => now()->addHours(3),
        ]);

        $countBefore = SessionRequest::where('user_id', $employee->id)->count();

        $this->login($employee->email, 'Password!123');

        $this->assertSame($countBefore, SessionRequest::where('user_id', $employee->id)->count());
    }

    public function test_a_login_with_no_current_approval_opens_a_pending_request(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office, [
            'email'    => 'emp-fresh@local',
            'password' => Hash::make('Password!123'),
        ]);

        $this->assertSame(0, SessionRequest::where('user_id', $employee->id)->count());

        $this->login($employee->email, 'Password!123');

        $this->assertSame(1, SessionRequest::where('user_id', $employee->id)
            ->where('status', SessionRequest::STATUS_PENDING)->count());
    }

    public function test_a_login_after_an_expired_approval_opens_a_new_request(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office, [
            'email'    => 'emp-expired@local',
            'password' => Hash::make('Password!123'),
        ]);

        // Yesterday's approval — no longer covers today's shift.
        SessionRequest::create([
            'tenant_id'    => $tenant->id,
            'user_id'      => $employee->id,
            'office_id'    => $office->id,
            'status'       => SessionRequest::STATUS_APPROVED,
            'requested_at' => now()->subDay(),
            'decided_at'   => now()->subDay(),
            'expires_at'   => now()->subHours(20),
        ]);

        $this->login($employee->email, 'Password!123');

        $this->assertSame(1, SessionRequest::where('user_id', $employee->id)
            ->where('status', SessionRequest::STATUS_PENDING)->count());
    }
}
