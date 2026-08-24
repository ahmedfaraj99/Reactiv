<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail for owner-level account lifecycle actions
 * (archive / unarchive / permanent delete). Deliberately independent of
 * the accounts table's foreign keys so it survives the account being
 * permanently erased — that's the entire point of an audit log.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property ?int   $actor_id
 * @property int    $account_id
 * @property string $account_email_masked
 * @property string $action
 */
class AccountAdminLog extends Model
{
    use HasFactory;

    public const ACTION_ARCHIVED           = 'archived';
    public const ACTION_UNARCHIVED         = 'unarchived';
    public const ACTION_PERMANENTLY_DELETED = 'permanently_deleted';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'actor_id', 'account_id', 'account_email_masked',
        'action', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
