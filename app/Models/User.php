<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int         $id
 * @property ?int        $tenant_id
 * @property ?int        $office_id
 * @property string      $name
 * @property string      $email
 * @property ?string     $phone
 * @property string      $password
 * @property ?string     $google2fa_secret
 * @property bool        $google2fa_enabled
 * @property ?string     $device_fingerprint
 * @property ?string     $last_login_ip
 * @property bool        $active
 */
#[ObservedBy([UserObserver::class])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'office_id',
        'name', 'email', 'email_verified_at', 'phone', 'password',
        'google2fa_secret', 'google2fa_enabled',
        'device_fingerprint', 'last_login_ip', 'last_login_at', 'active',
        'requires_proof',
    ];

    protected $hidden = ['password', 'remember_token', 'google2fa_secret'];

    // Model-level defaults so a freshly-created User (via ::create or the
    // form) reflects the DB column defaults without needing a ->fresh()
    // round-trip. Kept in sync with the migration column defaults.
    protected $attributes = [
        'requires_proof' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'last_login_at'      => 'datetime',
            'password'           => 'hashed',
            'google2fa_secret'   => 'encrypted',
            'google2fa_enabled'  => 'boolean',
            'active'             => 'boolean',
            'requires_proof'     => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Offices this user manages as a supervisor — a supervisor can be
     * responsible for more than one office (unlike an employee, who
     * belongs to exactly one via office_id above).
     */
    public function managedOffices(): HasMany
    {
        return $this->hasMany(Office::class, 'manager_id');
    }

    // ── Role helpers ─────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(UserRole::SuperAdmin->value);
    }

    public function isTenantOwner(): bool
    {
        return $this->hasRole(UserRole::TenantOwner->value);
    }

    public function isManager(): bool
    {
        return $this->hasRole(UserRole::Manager->value);
    }

    /**
     * Convenience predicate for the many places that treat owner + manager
     * as "administrators" of the tenant (uploading accounts, managing
     * offices, creating users, etc.).
     */
    public function isTenantOwnerOrManager(): bool
    {
        return $this->hasRole([
            UserRole::TenantOwner->value,
            UserRole::Manager->value,
        ]);
    }

    public function isSupervisor(): bool
    {
        return $this->hasRole(UserRole::Supervisor->value);
    }

    public function isEmployee(): bool
    {
        return $this->hasRole(UserRole::Employee->value);
    }

    /**
     * IDs of the employees this user is allowed to see on tenant-scoped
     * resource lists (alerts, reveal logs, assignments awaiting review, …).
     *  - Tenant owner: every employee in the tenant.
     *  - Manager: employees in offices they manage.
     *  - Supervisor: employees in their single office.
     *  - Everyone else: nobody.
     *
     * Returned as a collection so callers can pass it straight to a
     * `whereIn(..., $ids)`. Kept here because three different Filament
     * resources were re-implementing the same query verbatim.
     *
     * @return \Illuminate\Support\Collection<int,int>
     */
    public function visibleEmployeeIds(): \Illuminate\Support\Collection
    {
        $base = self::query()
            ->where('tenant_id', $this->tenant_id)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value));

        if ($this->isTenantOwner()) {
            return $base->pluck('id');
        }

        if ($this->isManager()) {
            return $base->whereIn('office_id', $this->managedOffices()->pluck('id'))->pluck('id');
        }

        if ($this->isSupervisor()) {
            return $base->where('office_id', $this->office_id)->pluck('id');
        }

        return collect();
    }

    // ── Filament panel access ────────────────────────────────────────

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->active) {
            return false;
        }

        // Freshly-created users must click the activation link in their
        // invitation email before they can log in — even guessing the
        // random placeholder password gets them nowhere while this is
        // null. Owners bypassing this via seeding are handled by the
        // seeder stamping email_verified_at explicitly.
        if ($this->email_verified_at === null) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->isSuperAdmin(),
            'app'   => $this->tenant_id !== null && ! $this->isSuperAdmin()
                && $this->tenant?->status !== 'suspended',
            default => false,
        };
    }

    // ── Filament multi-tenancy (HasTenants) ─────────────────────────

    public function getTenants(Panel $panel): Collection|array
    {
        return $this->tenant ? Collection::make([$this->tenant]) : [];
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Tenant && $tenant->id === $this->tenant_id;
    }
}
