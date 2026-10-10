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
    /** Taken off a link after the worker started on it — owner checks by hand. */
    public const STATUS_REVIEW    = 'review';

    public const PLATFORM_PSN  = 'psn';
    public const PLATFORM_XBOX = 'xbox';

    /** @var array<string,string> */
    public const PLATFORMS = [
        self::PLATFORM_PSN  => 'PlayStation',
        self::PLATFORM_XBOX => 'Xbox',
    ];

    /**
     * Two codes the worker can generate: the console sign-in (PSN or
     * Xbox) and EA (Ultimate Team asks for it). Each has the same base
     * allowance as activation — one code — and its own owner-approved
     * extras. Columns are the same name with an `ea_` prefix for EA.
     */
    public const KIND_CONSOLE = 'console';
    public const KIND_EA      = 'ea';

    public const TOTP_KINDS = [self::KIND_CONSOLE, self::KIND_EA];

    public const TOTP_BASE_LIMIT = 1;

    /** Fresh code state for an account leaving a batch. */
    public const RESET_TOTP = [
        'totp_generations'      => 0,
        'totp_extra_allowed'    => 0,
        'totp_requested_at'     => null,
        'first_totp_at'         => null,
        'ea_totp_generations'   => 0,
        'ea_totp_extra_allowed' => 0,
        'ea_totp_requested_at'  => null,
    ];

    protected $table = 'club_creation_accounts';

    protected $fillable = [
        'tenant_id', 'platform', 'email', 'password', 'ea_password', 'totp_seed', 'ea_totp_seed',
        'ea_backup_code', 'psn_backup_code',
        'status', 'batch_id', 'released_from_batch_id', 'done_at', 'exported_at',
        'totp_generations', 'totp_extra_allowed', 'totp_requested_at', 'first_totp_at',
        'ea_totp_generations', 'ea_totp_extra_allowed', 'ea_totp_requested_at',
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
            'ea_totp_generations'   => 'integer',
            'ea_totp_extra_allowed' => 'integer',
            'ea_totp_requested_at'  => 'datetime',
        ];
    }

    public function platformLabel(): string
    {
        return self::PLATFORMS[$this->platform] ?? $this->platform;
    }

    /** Column name for a TOTP field of the given kind (EA = `ea_` prefix). */
    public static function totpColumn(string $kind, string $field): string
    {
        return ($kind === self::KIND_EA ? 'ea_' : '').$field;
    }

    public function totpSeedFor(string $kind = self::KIND_CONSOLE): ?string
    {
        return $this->{self::totpColumn($kind, 'totp_seed')};
    }

    public function hasTotp(string $kind = self::KIND_CONSOLE): bool
    {
        $seed = $this->totpSeedFor($kind);

        return $seed !== null && $seed !== '';
    }

    public function totpUsed(string $kind = self::KIND_CONSOLE): int
    {
        return (int) $this->{self::totpColumn($kind, 'totp_generations')};
    }

    public function totpAllowance(string $kind = self::KIND_CONSOLE): int
    {
        return self::TOTP_BASE_LIMIT + (int) $this->{self::totpColumn($kind, 'totp_extra_allowed')};
    }

    public function canGenerateTotp(string $kind = self::KIND_CONSOLE): bool
    {
        return $this->totpUsed($kind) < $this->totpAllowance($kind);
    }

    public function hasPendingTotpRequest(string $kind = self::KIND_CONSOLE): bool
    {
        return $this->{self::totpColumn($kind, 'totp_requested_at')} !== null;
    }

    /**
     * Did the worker get anywhere with this account? Generating any code
     * means they at least signed in — it can't go back to the pool
     * without a human check.
     */
    public function wasTouched(): bool
    {
        return $this->totpUsed(self::KIND_CONSOLE) > 0
            || $this->totpUsed(self::KIND_EA) > 0
            || $this->first_totp_at !== null;
    }

    public function releasedFromBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'released_from_batch_id');
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
