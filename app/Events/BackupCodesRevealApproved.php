<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a supervisor or manager approves an employee's request to view
 * EA backup codes. Same pattern as TotpExtraAllowanceGranted — the employee's
 * activation page listens on its own private channel and refreshes the
 * revealed values so the codes appear immediately, no polling.
 */
class BackupCodesRevealApproved implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $employeeId,
        public int $assignmentId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->employeeId);
    }

    public function broadcastAs(): string
    {
        return 'backup_codes.approved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'assignmentId' => $this->assignmentId,
        ];
    }
}
