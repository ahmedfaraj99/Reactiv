<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\RevealLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log for every sensitive action a user takes on an
 * account: revealing credentials, generating a TOTP, or marking the
 * activation complete/failed. Never mutate rows.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property int    $user_id
 * @property int    $account_id
 * @property string $action
 * @property ?string $ip
 * @property ?string $user_agent
 * @property ?string $device_fingerprint
 */
#[ObservedBy([RevealLogObserver::class])]
class RevealLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'account_id',
        'action', 'ip', 'user_agent', 'device_fingerprint',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
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
}
