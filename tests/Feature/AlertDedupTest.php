<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Models\Alert;
use Tests\TestCase;

/**
 * Alert::raise() collapses storms of the same underlying event (login
 * brute-force from one IP, repeated new-device sightings for one user)
 * into ONE open row that gets bumped, so the owner isn't spammed by
 * mail while a single incident is unfolding. Once resolved, the next
 * occurrence opens a fresh alert (and re-notifies).
 */
class AlertDedupTest extends TestCase
{
    public function test_raise_without_a_dedup_key_creates_a_new_row_each_time(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant, UserRole::TenantOwner);

        for ($i = 0; $i < 3; $i++) {
            Alert::raise([
                'tenant_id' => $tenant->id,
                'type'      => AlertType::LoginAttack,
                'severity'  => AlertSeverity::Critical,
                'message'   => 'try '.$i,
            ]);
        }

        $this->assertSame(3, Alert::query()->count());
    }

    public function test_raise_with_a_dedup_key_bumps_the_open_alert_instead_of_creating_new(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant, UserRole::TenantOwner);

        $first = Alert::raise([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::LoginAttack,
            'severity'  => AlertSeverity::Critical,
            'message'   => 'attempt 1',
        ], dedupKey: 'login_attack:1.2.3.4');

        for ($i = 2; $i <= 5; $i++) {
            Alert::raise([
                'tenant_id' => $tenant->id,
                'type'      => AlertType::LoginAttack,
                'severity'  => AlertSeverity::Critical,
                'message'   => "attempt {$i}",
            ], dedupKey: 'login_attack:1.2.3.4');
        }

        $this->assertSame(1, Alert::query()->count());
        $bumped = Alert::query()->first();
        $this->assertSame($first->id, $bumped->id);
        $this->assertSame(5, $bumped->payload['bump_count']);
        $this->assertSame('attempt 5', $bumped->message);
    }

    public function test_a_resolved_alert_does_not_block_a_new_one_on_the_same_key(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant, UserRole::TenantOwner);

        $first = Alert::raise([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::LoginAttack,
            'severity'  => AlertSeverity::Critical,
            'message'   => 'incident 1',
        ], dedupKey: 'login_attack:1.2.3.4');

        $first->update(['resolved' => true, 'resolved_at' => now()]);

        $second = Alert::raise([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::LoginAttack,
            'severity'  => AlertSeverity::Critical,
            'message'   => 'incident 2',
        ], dedupKey: 'login_attack:1.2.3.4');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Alert::query()->count());
    }

    public function test_different_dedup_keys_do_not_collide(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant, UserRole::TenantOwner);

        Alert::raise([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::LoginAttack,
            'severity'  => AlertSeverity::Critical,
            'message'   => 'from A',
        ], dedupKey: 'login_attack:1.2.3.4');

        Alert::raise([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::LoginAttack,
            'severity'  => AlertSeverity::Critical,
            'message'   => 'from B',
        ], dedupKey: 'login_attack:5.6.7.8');

        $this->assertSame(2, Alert::query()->count());
    }

    public function test_bumping_does_not_fire_a_second_notification(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, UserRole::TenantOwner);

        Alert::raise([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::LoginAttack,
            'severity'  => AlertSeverity::Critical,
            'message'   => 'attempt 1',
        ], dedupKey: 'k1');

        Alert::raise([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::LoginAttack,
            'severity'  => AlertSeverity::Critical,
            'message'   => 'attempt 2',
        ], dedupKey: 'k1');

        // AlertObserver::created only fires on new rows, so the owner
        // sees exactly one bell notification — no email storm either.
        $this->assertSame(1, $owner->notifications()->count());
    }
}
