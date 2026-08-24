<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a supervisor approves a TOTP extra-code request. The employee
 * page listens on its own private channel and refreshes the assignment,
 * flipping the button from "بانتظار موافقة المشرف" to enabled without a
 * polling loop.
 */
class TotpExtraAllowanceGranted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $employeeId,
        public int $assignmentId,
        public string $platform,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->employeeId);
    }

    public function broadcastAs(): string
    {
        return 'totp.approved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'assignmentId' => $this->assignmentId,
            'platform'     => $this->platform,
        ];
    }
}
