<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Observers\AlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int           $id
 * @property int           $tenant_id
 * @property ?int          $user_id
 * @property ?int          $account_id
 * @property AlertType     $type
 * @property AlertSeverity $severity
 * @property ?string       $message
 * @property ?array        $payload
 * @property bool          $resolved
 */
#[ObservedBy([AlertObserver::class])]
class Alert extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'user_id', 'account_id',
        'type', 'severity', 'message', 'payload', 'dedup_key',
        'resolved', 'resolved_by', 'resolved_at',
    ];

    /**
     * Create an alert, or — if $dedupKey is given and an OPEN alert with
     * the same (tenant_id, dedup_key) already exists — bump the existing
     * one instead. The bump touches `updated_at` and increments
     * payload.bump_count so the owner can see repetition, without the
     * observer firing a fresh notification (`created` runs only on new
     * rows, not updates).
     *
     * Use for storms of the same underlying event: a brute-force login
     * from one IP, repeated new-device sightings for the same user, etc.
     * Callers that don't need dedup can keep using Alert::create().
     */
    public static function raise(array $attrs, ?string $dedupKey = null): self
    {
        if ($dedupKey === null) {
            return self::create($attrs);
        }

        return DB::transaction(function () use ($attrs, $dedupKey): self {
            $existing = self::query()
                ->where('tenant_id', $attrs['tenant_id'])
                ->where('dedup_key', $dedupKey)
                ->where('resolved', false)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $payload = $existing->payload ?? [];
                $payload['bump_count']     = ($payload['bump_count'] ?? 1) + 1;
                $payload['last_bumped_at'] = now()->toIso8601String();

                $existing->update([
                    'payload' => $payload,
                    'message' => $attrs['message'] ?? $existing->message,
                ]);

                return $existing;
            }

            return self::create([...$attrs, 'dedup_key' => $dedupKey]);
        });
    }

    protected function casts(): array
    {
        return [
            'type'        => AlertType::class,
            'severity'    => AlertSeverity::class,
            'payload'     => 'array',
            'resolved'    => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
