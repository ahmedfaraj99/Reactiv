<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Employee's own channel — only the employee themselves subscribes.
// Used for TOTP extra-code approvals and other per-user pushes.
Broadcast::channel('user.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

// Tenant-scoped channel — every user in the tenant subscribes, used for
// emergency freeze/unfreeze and any tenant-wide state flip.
Broadcast::channel('tenant.{tenantId}', function (User $user, int $tenantId): bool {
    return $user->tenant_id === $tenantId;
});

// Approvals scope. Tenant-wide channel is owner-only — the owner is the
// only role whose approvals view spans every office. Managers are scoped
// to the offices they manage, so they subscribe per-office alongside the
// office's supervisor (who is scoped by office_id).
Broadcast::channel('tenant.{tenantId}.approvals', function (User $user, int $tenantId): bool {
    return $user->tenant_id === $tenantId && $user->isTenantOwner();
});

Broadcast::channel('office.{officeId}.approvals', function (User $user, int $officeId): bool {
    if ($user->isSupervisor() && $user->office_id === $officeId) {
        return true;
    }

    if ($user->isManager()) {
        return $user->managedOffices()->whereKey($officeId)->exists();
    }

    return false;
});
