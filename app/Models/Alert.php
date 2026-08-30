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
        'type', 'severity', 'message', 'payload',
        'resolved', 'resolved_by', 'resolved_at',
    ];

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
