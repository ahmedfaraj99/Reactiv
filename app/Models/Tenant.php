<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int    $id
 * @property string $name
 * @property string $slug
 * @property ?string $subdomain
 * @property ?string $encryption_key_id
 * @property string $status
 * @property string $plan
 * @property int    $max_accounts
 * @property int    $max_employees
 * @property ?float $commission_per_activation     Activation-only accounts
 * @property ?float $commission_per_match_account  Accounts with the play-N-matches add-on
 * @property ?int   $default_daily_target
 * @property ?\Illuminate\Support\Carbon $frozen_at
 * @property ?string $frozen_reason
 * @property ?int   $frozen_by
 */
class Tenant extends Model implements HasName
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'subdomain', 'encryption_key_id',
        'status', 'plan', 'max_accounts', 'max_employees', 'trial_ends_at',
        'commission_per_activation', 'commission_per_match_account', 'default_daily_target',
        'frozen_at', 'frozen_reason', 'frozen_by',
    ];

    protected function casts(): array
    {
        return [
            'max_accounts'               => 'integer',
            'max_employees'              => 'integer',
            'trial_ends_at'              => 'datetime',
            'commission_per_activation'  => 'decimal:2',
            'commission_per_match_account' => 'decimal:2',
            'default_daily_target'       => 'integer',
            'frozen_at'                  => 'datetime',
        ];
    }

    /** Whether the tenant-wide emergency freeze is active right now. */
    public function hasCommissionRates(): bool
    {
        return $this->commission_per_activation !== null || $this->commission_per_match_account !== null;
    }

    /**
     * Payout for a mix of completed accounts, or null when no rate is set
     * (pages then show counts only). A rate left blank counts as 0, so an
     * office that only does one kind needs to set only that price.
     */
    public function commissionFor(int $activationOnlyCount, int $matchAccountCount): ?float
    {
        if (! $this->hasCommissionRates()) {
            return null;
        }

        return $activationOnlyCount * (float) $this->commission_per_activation
            + $matchAccountCount * (float) $this->commission_per_match_account;
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    public function freezer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by');
    }

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            if (empty($tenant->slug)) {
                $tenant->slug = Str::slug($tenant->name).'-'.Str::lower(Str::random(4));
            }
        });
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    // ── Relationships ────────────────────────────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
