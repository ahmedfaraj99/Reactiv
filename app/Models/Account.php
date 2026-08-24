<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An FC account is one shared email plus a separate PSN password/2FA seed
 * and a separate EA password/2FA seed. Both the EA email and EA password
 * only differ from the PSN ones in some cases — when null, the PSN value
 * is used for EA too (see effectiveEaEmail() / effectiveEaPassword()).
 *
 * @property int     $id
 * @property int     $tenant_id
 * @property string  $email
 * @property string  $email_fingerprint
 * @property string  $psn_password
 * @property string  $psn_totp_seed
 * @property ?string $ea_email
 * @property ?string $ea_email_fingerprint
 * @property ?string $ea_password
 * @property string  $ea_totp_seed
 * @property ?string $ea_backup_code_1
 * @property ?string $ea_backup_code_2
 * @property string  $status
 * @property ?int    $uploaded_by
 * @property ?int    $manager_id
 * @property ?string $import_batch_id
 * @property int     $matches_required
 */
class Account extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'email', 'email_fingerprint',
        'psn_password', 'psn_totp_seed',
        'ea_email', 'ea_email_fingerprint', 'ea_password', 'ea_totp_seed',
        'ea_backup_code_1', 'ea_backup_code_2',
        'status', 'uploaded_by', 'manager_id', 'client_id', 'activated_at', 'notes',
        'matches_required', 'import_batch_id',
    ];

    protected $hidden = [
        'psn_password', 'psn_totp_seed', 'ea_password', 'ea_totp_seed',
        'ea_backup_code_1', 'ea_backup_code_2',
    ];

    protected function casts(): array
    {
        return [
            'email'             => 'encrypted',
            'psn_password'      => 'encrypted',
            'psn_totp_seed'     => 'encrypted',
            'ea_email'          => 'encrypted',
            'ea_password'       => 'encrypted',
            'ea_totp_seed'      => 'encrypted',
            'ea_backup_code_1'  => 'encrypted',
            'ea_backup_code_2'  => 'encrypted',
            'activated_at'      => 'datetime',
            'matches_required'  => 'integer',
        ];
    }

    /** True when the customer paid for the "play N matches" add-on. */
    public function requiresMatches(): bool
    {
        return $this->matches_required > 0;
    }

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if ($account->isDirty('email')) {
                $account->email_fingerprint = self::fingerprint((string) $account->email);
            }
            if ($account->isDirty('ea_email')) {
                $account->ea_email_fingerprint = $account->ea_email !== null
                    ? self::fingerprint((string) $account->ea_email)
                    : null;
            }
        });
    }

    /**
     * The email EA actually logs in with — the override if one was set,
     * otherwise the primary email (the common case).
     */
    public function effectiveEaEmail(): string
    {
        return $this->ea_email ?? $this->email;
    }

    /**
     * The password EA actually logs in with — the override if one was
     * set, otherwise the PSN password (the common case).
     */
    public function effectiveEaPassword(): string
    {
        return $this->ea_password ?? $this->psn_password;
    }

    /**
     * Not every account has EA backup codes — only present when the
     * uploader supplied them.
     */
    public function hasEaBackupCodes(): bool
    {
        return $this->ea_backup_code_1 !== null && $this->ea_backup_code_2 !== null;
    }

    /**
     * Deterministic fingerprint of an email — SHA-256 of the lowercased
     * value with an app-key-derived salt. Same input always yields the
     * same fingerprint so we can query for duplicates.
     */
    public static function fingerprint(string $email): string
    {
        $normalized = mb_strtolower(trim($email));
        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    // ── Relationships ────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(AccountAssignment::class);
    }

    public function revealLogs(): HasMany
    {
        return $this->hasMany(RevealLog::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
