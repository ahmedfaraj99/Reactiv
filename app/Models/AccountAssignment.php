<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int     $id
 * @property int     $tenant_id
 * @property int     $account_id
 * @property int     $employee_id
 * @property ?int    $supervisor_id
 * @property string  $status
 * @property ?string $proof_path
 * @property ?int    $reviewed_by
 */
class AccountAssignment extends Model
{
    use HasFactory;

    public const STATUS_PENDING         = 'pending';
    public const STATUS_IN_PROGRESS     = 'in_progress';
    public const STATUS_AWAITING_REVIEW = 'awaiting_review';
    public const STATUS_COMPLETED       = 'completed';
    public const STATUS_FAILED          = 'failed';

    /** Base 2FA-code pulls allowed before a supervisor must approve more. */
    public const PSN_TOTP_BASE_LIMIT = 1;
    public const EA_TOTP_BASE_LIMIT  = 2;

    protected $fillable = [
        'tenant_id', 'account_id', 'employee_id', 'supervisor_id',
        'status', 'assigned_at', 'started_at', 'credentials_revealed_at', 'first_totp_at',
        'completed_at', 'notes',
        'proof_path', 'proof_hash', 'submitted_at', 'reviewed_by', 'reviewed_at', 'rejection_reason',
        'psn_totp_generations', 'ea_totp_generations',
        'psn_totp_extra_allowed', 'ea_totp_extra_allowed',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at'             => 'datetime',
            'started_at'              => 'datetime',
            'credentials_revealed_at' => 'datetime',
            'first_totp_at'           => 'datetime',
            'completed_at'            => 'datetime',
            'submitted_at'            => 'datetime',
            'reviewed_at'             => 'datetime',
        ];
    }

    /**
     * The employee's actual work time on this assignment — from the moment
     * they saw the credentials until they submitted the proof. Excludes
     * supervisor-review lag (which is why completed_at - started_at is a
     * bad proxy for employee speed).
     */
    public function activationSeconds(): ?int
    {
        if ($this->credentials_revealed_at === null || $this->submitted_at === null) {
            return null;
        }
        return (int) $this->credentials_revealed_at->diffInSeconds($this->submitted_at);
    }

    /**
     * Faster than physically possible? For an activation-only account we
     * budget 30 seconds (typing creds + one snap). For a matches account
     * we add a very forgiving 90 seconds per match — real FIFA matches
     * run ~6 min of gameplay plus menus (10+ min), so 90s/match is
     * generous enough to never false-alarm and tight enough to catch
     * "screenshot and submit without playing".
     */
    public function isSuspiciouslyFast(int $matchesRequired): bool
    {
        $seconds = $this->activationSeconds();
        if ($seconds === null) {
            return false;
        }
        return $seconds < 30 + ($matchesRequired * 90);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function psnTotpAllowance(): int
    {
        return self::PSN_TOTP_BASE_LIMIT + $this->psn_totp_extra_allowed;
    }

    public function eaTotpAllowance(): int
    {
        return self::EA_TOTP_BASE_LIMIT + $this->ea_totp_extra_allowed;
    }

    public function canGeneratePsnTotp(): bool
    {
        return $this->psn_totp_generations < $this->psnTotpAllowance();
    }

    public function canGenerateEaTotp(): bool
    {
        return $this->ea_totp_generations < $this->eaTotpAllowance();
    }

    /**
     * True once the employee has actually started pulling 2FA codes for
     * this activation — from that point on they're locked to it until
     * they complete it or flag it as failed (see lockedFor()).
     */
    public function hasStartedTotp(): bool
    {
        return $this->psn_totp_generations > 0 || $this->ea_totp_generations > 0;
    }

    /**
     * The one account this employee is currently locked into, if any —
     * an in-progress assignment where they've already pulled at least
     * one 2FA code. Prevents hopping between accounts mid-activation.
     */
    public static function lockedFor(int $employeeId): ?self
    {
        return self::query()
            ->where('employee_id', $employeeId)
            ->where('status', self::STATUS_IN_PROGRESS)
            ->where(function ($q): void {
                $q->where('psn_totp_generations', '>', 0)
                    ->orWhere('ea_totp_generations', '>', 0);
            })
            ->first();
    }
}
