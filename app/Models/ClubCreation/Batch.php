<?php

declare(strict_types=1);

namespace App\Models\ClubCreation;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int         $id
 * @property string      $token
 * @property string      $recipient
 * @property int         $account_count
 * @property string      $price_per_account
 * @property ?string     $notes
 * @property ?\Illuminate\Support\Carbon $opened_at
 */
class Batch extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'club_creation_batches';

    protected $fillable = [
        'tenant_id', 'token', 'recipient', 'account_count',
        'price_per_account', 'notes', 'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'account_count'     => 'integer',
            'price_per_account' => 'decimal:2',
            'opened_at'         => 'datetime',
        ];
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
}
