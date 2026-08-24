<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int     $id
 * @property int     $tenant_id
 * @property string  $name
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $notes
 */
class Client extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'email', 'phone', 'notes'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
