<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Any session-request state change (new pending request, approved, denied,
 * revoked). Broadcast to BOTH the tenant-wide approvals channel (managers
 * and the owner subscribe) and the office-scoped one (supervisors) so the
 * inbox refreshes for exactly the users who can act on it.
 */
class SessionRequestChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $officeId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.'.$this->tenantId.'.approvals'),
            new PrivateChannel('office.'.$this->officeId.'.approvals'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'session-request.changed';
    }
}
