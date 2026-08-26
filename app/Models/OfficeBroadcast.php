<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int      $id
 * @property int      $tenant_id
 * @property ?int     $office_id
 * @property int      $sender_id
 * @property string   $message
 * @property string   $level
 * @property ?\Illuminate\Support\Carbon $expires_at
 */
class OfficeBroadcast extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const LEVEL_INFO    = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_DANGER  = 'danger';

    protected $fillable = [
        'tenant_id', 'office_id', 'sender_id',
        'message', 'level', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Not-expired broadcasts (a NULL expiry never expires). Callers
     * still combine this with a tenant/office scope — the visibility
     * rule of who can see a given broadcast lives in the banner query,
     * not here, because it depends on the viewing user's role.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    // ── Relationships ────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
