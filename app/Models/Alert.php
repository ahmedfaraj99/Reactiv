<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\AlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $tenant_id
 * @property ?int   $user_id
 * @property ?int   $account_id
 * @property string $type
 * @property string $severity
 * @property ?string $message
 * @property ?array $payload
 * @property bool   $resolved
 */
#[ObservedBy([AlertObserver::class])]
class Alert extends Model
{
    use HasFactory;

    public const TYPE_REPEAT_REVEAL = 'repeat_reveal';
    public const TYPE_NEW_DEVICE    = 'new_device';
    public const TYPE_OFF_HOURS     = 'off_hours';
    public const TYPE_HIGH_VOLUME   = 'high_volume';
    public const TYPE_TOTP_LIMIT    = 'totp_limit';
    public const TYPE_LOGIN_ATTACK  = 'login_attack';
    public const TYPE_ASSIGNMENT_OVERDUE = 'assignment_overdue';
    public const TYPE_ASSIGNMENTS_RELEASED = 'assignments_released';
    public const TYPE_DUPLICATE_PROOF = 'duplicate_proof';
    public const TYPE_SUSPICIOUS_SPEED = 'suspicious_speed';
    public const TYPE_EMERGENCY_FREEZE = 'emergency_freeze';

    protected $fillable = [
        'tenant_id', 'user_id', 'account_id',
        'type', 'severity', 'message', 'payload',
        'resolved', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'     => 'array',
            'resolved'    => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
