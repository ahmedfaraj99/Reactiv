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

// Approvals scope: managers/owners see everything in the tenant,
// supervisors see only their office. Two channels — the client
// subscribes to the one matching its role — is simpler than a single
// channel with client-side filtering (which would also broadcast noise
// to supervisors from other offices).
Broadcast::channel('tenant.{tenantId}.approvals', function (User $user, int $tenantId): bool {
    return $user->tenant_id === $tenantId && ($user->isTenantOwner() || $user->isManager());
});

Broadcast::channel('office.{officeId}.approvals', function (User $user, int $officeId): bool {
    return $user->office_id === $officeId && $user->isSupervisor();
});
