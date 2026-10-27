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
 * @property ?string $code   neutral label (C01 …) shown to managers instead of the name
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $notes
 */
class Client extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'code', 'email', 'phone', 'notes'];

    protected static function booted(): void
    {
        static::saving(function (self $client): void {
            if (blank($client->code)) {
                $client->code = self::nextCode((int) $client->tenant_id);
            }
        });
    }

    /**
     * Next free C-number in the tenant. Deleted clients keep their code
     * so a number is never handed to a different client later.
     */
    public static function nextCode(int $tenantId): string
    {
        $max = self::withTrashed()
            ->where('tenant_id', $tenantId)
            ->pluck('code')
            ->map(fn (?string $c): int => preg_match('/^C(\d+)$/', (string) $c, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return 'C'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * What staff below the owner see for a client — the code only. The
     * owner gets the name alongside it.
     */
    public static function labelFor(?int $clientId): string
    {
        if ($clientId === null) {
            return 'بدون عميل';
        }

        $client = self::withTrashed()->find($clientId);
        if ($client === null) {
            return '#'.$clientId;
        }

        $code = $client->code ?? '#'.$clientId;

        return (auth()->user()?->isTenantOwner() ?? false) ? "{$code} — {$client->name}" : $code;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
