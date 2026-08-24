<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int      $id
 * @property int      $tenant_id
 * @property ?int     $client_id
 * @property int      $wiped_by
 * @property int      $accounts_wiped
 * @property ?string  $ip
 * @property \Illuminate\Support\Carbon $wiped_at
 */
class WipeLog extends Model
{
    // Deliberately no updated_at column — this is an append-only audit log.
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'client_id', 'wiped_by', 'accounts_wiped', 'ip', 'wiped_at'];

    protected function casts(): array
    {
        return [
            'wiped_at'       => 'datetime',
            'accounts_wiped' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function wiper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wiped_by');
    }
}
