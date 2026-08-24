<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\UserResource\Pages;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource;
use App\Filament\App\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $role = UserResource::managedRole();

        if ($role === null) {
            throw new UnauthorizedException('غير مسموح بإنشاء مستخدم.', 403);
        }

        $tenant = filament()->getTenant();

        if ($role === UserRole::Employee && $tenant !== null) {
            $currentEmployees = User::query()
                ->where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value))
                ->count();

            if ($currentEmployees >= $tenant->max_employees) {
                throw ValidationException::withMessages([
                    'name' => "وصلت للحد الأقصى لعدد الموظفين المسموح به ({$tenant->max_employees}) لخطة هذه الشركة.",
                ]);
            }
        }

        $data['password'] = Hash::make((string) $data['password']);
        $data['tenant_id'] = filament()->getTenant()?->id;

        /** @var User $user */
        $user = static::getModel()::create($data);
        $user->assignRole($role->value);

        if ($role === UserRole::Employee) {
            Cache::forget(AccountResource::employeeOptionsCacheKey((int) auth()->id()));
        }

        return $user;
    }
}
