<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int     $id
 * @property int     $tenant_id
 * @property int     $user_id
 * @property ?int    $office_id
 * @property string  $status
 * @property \Illuminate\Support\Carbon $requested_at
 * @property ?\Illuminate\Support\Carbon $decided_at
 * @property ?int    $decided_by
 * @property ?\Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $revoked_at
 * @property ?string $ip
 * @property ?string $user_agent
 * @property ?string $device_fingerprint
 * @property ?string $notes
 */
class SessionRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED   = 'denied';
    public const STATUS_REVOKED  = 'revoked';
    public const STATUS_EXPIRED  = 'expired';

    protected $fillable = [
        'tenant_id', 'user_id', 'office_id',
        'status', 'requested_at', 'decided_at', 'decided_by',
        'expires_at', 'revoked_at',
        'ip', 'user_agent', 'device_fingerprint', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at'   => 'datetime',
            'expires_at'   => 'datetime',
            'revoked_at'   => 'datetime',
        ];
    }

    /**
     * True only if the approval is currently effective — approved status
     * AND not expired AND not revoked. Callers that need to distinguish
     * the failure reason should check status directly.
     */
    public function isCurrentlyValid(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPROVED)
            ->whereNull('revoked_at')
            ->where(fn (Builder $b) => $b->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    // ── Relationships ───────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * The single currently-valid approval for a user, if any.
     * Used by the middleware on every request — must be indexed.
     */
    public static function currentFor(int $userId): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->active()
            ->latest('id')
            ->first();
    }
}
