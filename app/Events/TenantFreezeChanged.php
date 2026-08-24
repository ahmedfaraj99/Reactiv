<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Owner flipped the emergency freeze kill switch. Every page in the tenant
 * listens and reloads the widget/banner so the freeze state propagates
 * within a second — critical for stopping in-flight credential reveals
 * during an incident.
 */
class TenantFreezeChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $tenantId,
        public bool $frozen,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tenant.'.$this->tenantId);
    }

    public function broadcastAs(): string
    {
        return 'tenant.freeze';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['frozen' => $this->frozen];
    }
}
