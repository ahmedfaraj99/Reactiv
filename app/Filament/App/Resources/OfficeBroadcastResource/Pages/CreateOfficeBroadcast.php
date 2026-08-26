<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\OfficeBroadcastResource\Pages;

use App\Filament\App\Resources\OfficeBroadcastResource;
use App\Models\Office;
use Filament\Resources\Pages\CreateRecord;

class CreateOfficeBroadcast extends CreateRecord
{
    protected static string $resource = OfficeBroadcastResource::class;

    /**
     * Stamp sender/tenant server-side. Reject a tenant-wide (null
     * office) post from anyone but the owner, and reject an office
     * post targeting an office the caller does not manage — the form
     * options already restrict this, but a hand-crafted Livewire
     * payload could still smuggle a foreign office id, so re-check.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user   = auth()->user();
        $tenant = filament()->getTenant();
        abort_if($user === null || $tenant === null, 403);

        $officeId = $data['office_id'] ?? null;
        if ($officeId === null) {
            abort_unless($user->isTenantOwner(), 403);
        } else {
            $office = Office::query()->where('tenant_id', $tenant->id)->find($officeId);
            abort_if($office === null, 403);
            if ($user->isManager()) {
                abort_unless($office->manager_id === $user->id, 403);
            }
        }

        $data['tenant_id'] = $tenant->id;
        $data['sender_id'] = $user->id;

        return $data;
    }
}
