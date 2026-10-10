<?php

declare(strict_types=1);

namespace App\Models\ClubCreation;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int         $id
 * @property string      $token
 * @property string      $recipient
 * @property int         $account_count
 * @property string      $price_per_account
 * @property ?string     $notes
 * @property ?\Illuminate\Support\Carbon $opened_at
 * @property ?\Illuminate\Support\Carbon $closed_at
 */
class Batch extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'club_creation_batches';

    protected $fillable = [
        'tenant_id', 'platform', 'token', 'recipient', 'account_count',
        'price_per_account', 'notes', 'opened_at',
        'first_open_ip', 'first_open_ua',
        'revoked_at', 'closed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'account_count'     => 'integer',
            'price_per_account' => 'decimal:2',
            'opened_at'         => 'datetime',
            'revoked_at'        => 'datetime',
            'closed_at'         => 'datetime',
            'expires_at'        => 'datetime',
        ];
    }

    public function platformLabel(): string
    {
        return Account::PLATFORMS[$this->platform] ?? $this->platform;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isLive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            if (empty($batch->token)) {
                $batch->token = self::freshToken();
            }
        });
    }

    public static function freshToken(): string
    {
        do {
            $t = Str::lower(Str::random(16));
        } while (self::where('token', $t)->exists());

        return $t;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'batch_id');
    }

    public function publicUrl(): string
    {
        return route('club-creation.deliver', [
            'token' => $this->token,
            'slug'  => $this->recipientSlug(),
        ]);
    }

    /**
     * URL-safe rendering of the recipient label to embed in the
     * delivery link path so a link forwarded to the wrong person is
     * visibly wrong at a glance. Cosmetic only — the controller
     * matches on the token alone.
     */
    public function recipientSlug(): string
    {
        $recipient = trim((string) $this->recipient);
        if ($recipient === '') {
            return 'link';
        }

        // Try Str::slug first for latin names; if the result is empty
        // (pure Arabic / non-latin), fall back to a manual pass that
        // preserves unicode letters and digits.
        $slug = Str::slug($recipient, '-', 'en');

        if ($slug === '') {
            $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $recipient) ?? '';
            $slug = trim($slug, '-');
        }

        if ($slug === '') {
            return 'link';
        }

        return Str::limit($slug, 40, '');
    }

    public function doneCount(): int
    {
        return $this->accounts()->whereNotNull('done_at')->count();
    }

    public function isFullyDone(): bool
    {
        return $this->account_count > 0 && $this->doneCount() >= $this->account_count;
    }

    /**
     * What the customer is owed for: everything requested while the
     * batch is open, only what was actually finished once it's closed.
     */
    public function billableCount(): int
    {
        return $this->isClosed() ? $this->doneCount() : $this->account_count;
    }

    public function totalPrice(): float
    {
        return (float) $this->price_per_account * $this->billableCount();
    }

    /**
     * Customer finished part of the batch and stopped: kill the link,
     * keep the done accounts, release the rest (see releaseAccounts).
     *
     * @return array{available:int, review:int}
     */
    public function close(): array
    {
        return DB::transaction(function (): array {
            $this->update([
                'closed_at'  => now(),
                'revoked_at' => $this->revoked_at ?? now(),
            ]);

            return $this->releaseAccounts(null, shrink: false);
        });
    }

    /**
     * Take unfinished accounts off this batch. Untouched ones (no code
     * ever generated) go straight back to the pool; anything the worker
     * started goes to `review` for the owner to check by hand. Codes,
     * allowances and pending requests are reset either way so the next
     * link starts clean.
     *
     * $shrink lowers account_count — used when the customer changes
     * their mind and wants fewer, so the link and the bill shrink too.
     *
     * @param  list<int>|null  $accountIds  null = every unfinished account
     * @return array{available:int, review:int}
     */
    public function releaseAccounts(?array $accountIds, bool $shrink): array
    {
        return DB::transaction(function () use ($accountIds, $shrink): array {
            $accounts = Account::query()
                ->where('batch_id', $this->id)
                ->where('status', Account::STATUS_ASSIGNED)
                ->when($accountIds !== null, fn ($q) => $q->whereIn('id', $accountIds))
                ->lockForUpdate()
                ->get();

            $result = ['available' => 0, 'review' => 0];

            foreach ($accounts as $account) {
                $toReview = $account->wasTouched();

                $account->update([
                    'status'                 => $toReview ? Account::STATUS_REVIEW : Account::STATUS_AVAILABLE,
                    'batch_id'               => null,
                    'released_from_batch_id' => $toReview ? $this->id : null,
                ] + Account::RESET_TOTP);

                $result[$toReview ? 'review' : 'available']++;
            }

            if ($shrink && $accounts->isNotEmpty()) {
                $this->update(['account_count' => max(0, $this->account_count - $accounts->count())]);
            }

            return $result;
        });
    }
}
