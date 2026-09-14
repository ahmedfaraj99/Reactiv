<?php

declare(strict_types=1);

namespace App\Models\ClubCreation;

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
 */
class Account extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED  = 'assigned';
    public const STATUS_DONE      = 'done';
    public const STATUS_EXPORTED  = 'exported';

    protected $table = 'club_creation_accounts';

    protected $fillable = [
        'email', 'password', 'status', 'batch_id',
        'done_at', 'exported_at',
    ];

    protected function casts(): array
    {
        return [
            'done_at'     => 'datetime',
            'exported_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
