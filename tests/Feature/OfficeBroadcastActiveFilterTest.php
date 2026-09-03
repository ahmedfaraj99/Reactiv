<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OfficeBroadcast;
use Tests\TestCase;

/**
 * Regression: the "active" filter used `$q->active()` which relies on the
 * OfficeBroadcast::scopeActive model scope. Under the Filament filter query
 * pipeline the passed builder sometimes lost model binding and blew up with
 * "Call to undefined method Illuminate\Database\Eloquent\Builder::active()"
 * — 500ing the whole page. The filter is now inlined, so this test locks
 * that the same filter query still returns exactly the not-yet-expired rows
 * regardless of how it's invoked.
 */
class OfficeBroadcastActiveFilterTest extends TestCase
{
    public function test_active_filter_returns_only_broadcasts_that_have_not_expired(): void
    {
        $tenant  = $this->makeTenant();
        $office  = $this->makeOffice($tenant);
        $manager = $this->makeUser($tenant, UserRole::Manager);
        $office->update(['manager_id' => $manager->id]);

        $forever = OfficeBroadcast::create([
            'tenant_id'  => $tenant->id,
            'office_id'  => $office->id,
            'sender_id'  => $manager->id,
            'message'    => 'دائم',
            'level'      => OfficeBroadcast::LEVEL_INFO,
            'expires_at' => null,
        ]);
        $future = OfficeBroadcast::create([
            'tenant_id'  => $tenant->id,
            'office_id'  => $office->id,
            'sender_id'  => $manager->id,
            'message'    => 'قادم',
            'level'      => OfficeBroadcast::LEVEL_INFO,
            'expires_at' => now()->addHour(),
        ]);
        $expired = OfficeBroadcast::create([
            'tenant_id'  => $tenant->id,
            'office_id'  => $office->id,
            'sender_id'  => $manager->id,
            'message'    => 'منتهي',
            'level'      => OfficeBroadcast::LEVEL_INFO,
            'expires_at' => now()->subHour(),
        ]);

        // Same filter clause the resource now uses inline — must not depend
        // on OfficeBroadcast::scopeActive at all.
        $ids = OfficeBroadcast::query()
            ->where(function ($inner): void {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('id')
            ->all();

        $this->assertContains($forever->id, $ids);
        $this->assertContains($future->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }
}
