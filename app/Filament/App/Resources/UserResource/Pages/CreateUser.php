<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\UserResource\Pages;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource;
use App\Filament\App\Resources\UserResource;
use App\Models\User;
use App\Notifications\UserActivationInvitation;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

        // New users are created WITHOUT a working password. They set one
        // themselves via the signed link in the activation email, which
        // also stamps email_verified_at. Until then, canAccessPanel()
        // (checked below in the User model) refuses login even if
        // someone tries to guess the random placeholder.
        $data['password'] = Hash::make(Str::random(64));
        $data['email_verified_at'] = null;
        $data['active'] = false;
        $data['tenant_id'] = filament()->getTenant()?->id;

        /** @var User $user */
        $user = static::getModel()::create($data);
        $user->assignRole($role->value);

        if ($role === UserRole::Employee) {
            Cache::forget(AccountResource::employeeOptionsCacheKey((int) auth()->id()));
        }

        try {
            $user->notify(new UserActivationInvitation);

            Notification::make()
                ->title('تم إنشاء الحساب')
                ->body('تم إرسال رابط تفعيل إلى '.$user->email.'. صالح 72 ساعة.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            \Log::error('Activation email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            Notification::make()
                ->title('تم إنشاء الحساب لكن الإيميل لم يُرسل')
                ->body('راجع إعدادات SMTP، أو استخدم زر "إعادة إرسال دعوة" من قائمة المستخدمين.')
                ->warning()
                ->persistent()
                ->send();
        }

        return $user;
    }
}
