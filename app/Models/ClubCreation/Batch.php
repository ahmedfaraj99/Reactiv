<?php

declare(strict_types=1);

namespace App\Models\ClubCreation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'token', 'recipient', 'account_count',
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

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'batch_id');
    }

    public function publicUrl(): string
    {
        return route('club-creation.deliver', ['token' => $this->token]);
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
