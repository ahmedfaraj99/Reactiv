<?php

declare(strict_types=1);

namespace App\Models\ClubCreation;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int         $id
 * @property string      $email
 * @property string      $password
 * @property string      $status
 * @property ?int        $batch_id
 * @property ?\Illuminate\Support\Carbon $done_at
 * @property ?\Illuminate\Support\Carbon $exported_at
 * @property ?string     $ea_password
 * @property ?string     $totp_seed      PSN GAUTH
 * @property ?string     $ea_totp_seed   EA GAUTH
 * @property int         $totp_generations
 * @property int         $totp_extra_allowed
 * @property ?\Illuminate\Support\Carbon $totp_requested_at
 * @property ?\Illuminate\Support\Carbon $first_totp_at
 */
class Account extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED  = 'assigned';
    public const STATUS_DONE      = 'done';
    public const STATUS_EXPORTED  = 'exported';

    public const PLATFORM_PSN  = 'psn';
    public const PLATFORM_XBOX = 'xbox';

    /** @var array<string,string> */
    public const PLATFORMS = [
        self::PLATFORM_PSN  => 'PlayStation',
        self::PLATFORM_XBOX => 'Xbox',
    ];

    /**
     * Same base allowance as an activation's console (PSN) code — one
     * code per account; anything beyond needs the owner's approval.
     */
    public const TOTP_BASE_LIMIT = 1;

    protected $table = 'club_creation_accounts';

    protected $fillable = [
        'tenant_id', 'platform', 'email', 'password', 'ea_password', 'totp_seed', 'ea_totp_seed',
        'ea_backup_code', 'psn_backup_code',
        'status', 'batch_id', 'done_at', 'exported_at',
        'totp_generations', 'totp_extra_allowed', 'totp_requested_at', 'first_totp_at',
    ];

    protected $hidden = ['totp_seed', 'ea_totp_seed'];

    protected function casts(): array
    {
        return [
            'done_at'            => 'datetime',
            'exported_at'        => 'datetime',
            'totp_seed'          => 'encrypted',
            'ea_totp_seed'       => 'encrypted',
            'totp_generations'   => 'integer',
            'totp_extra_allowed' => 'integer',
            'totp_requested_at'  => 'datetime',
            'first_totp_at'      => 'datetime',
        ];
    }

    public function platformLabel(): string
    {
        return self::PLATFORMS[$this->platform] ?? $this->platform;
    }

    public function hasTotp(): bool
    {
        return $this->totp_seed !== null && $this->totp_seed !== '';
    }

    public function totpAllowance(): int
    {
        return self::TOTP_BASE_LIMIT + $this->totp_extra_allowed;
    }

    public function canGenerateTotp(): bool
    {
        return $this->totp_generations < $this->totpAllowance();
    }

    public function hasPendingTotpRequest(): bool
    {
        return $this->totp_requested_at !== null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
