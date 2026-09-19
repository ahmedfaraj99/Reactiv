<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EmployeeResource\Pages;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource;
use App\Filament\App\Resources\EmployeeResource;
use App\Models\User;
use App\Notifications\UserActivationInvitation;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $u = auth()->user();
        if ($u === null || ! $u->isManager()) {
            throw new UnauthorizedException('غير مسموح بإنشاء موظف.', 403);
        }

        $tenant = filament()->getTenant();
        if ($tenant === null) {
            throw new UnauthorizedException('لا يوجد مستأجر نشط.', 403);
        }

        $currentEmployees = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value))
            ->count();

        if ($currentEmployees >= $tenant->max_employees) {
            throw ValidationException::withMessages([
                'name' => "وصلت للحد الأقصى لعدد الموظفين المسموح به ({$tenant->max_employees}) لخطة هذه الشركة.",
            ]);
        }

        // New employees are created WITHOUT a working password. They set
        // one themselves via the signed link in the activation email,
        // which also stamps email_verified_at. Until then,
        // canAccessPanel() refuses login even if someone tries to guess
        // the random placeholder.
        $data['password']          = Hash::make(Str::random(64));
        $data['email_verified_at'] = null;
        $data['active']            = false;
        $data['tenant_id']         = $tenant->id;

        /** @var User $employee */
        $employee = User::create($data);
        $employee->assignRole(UserRole::Employee->value);

        Cache::forget(AccountResource::employeeOptionsCacheKey((int) $u->id));

        $activationUrl = URL::temporarySignedRoute(
            'activation.show',
            now()->addHours(72),
            ['user' => $employee->getKey()],
        );

        try {
            $employee->notify(new UserActivationInvitation);
            Notification::make()
                ->title('تم إنشاء الموظف')
                ->body('تم إرسال رابط تفعيل إلى '.$employee->email.'. أو انسخ الرابط من هنا وأرسله عبر واتساب: '.$activationUrl)
                ->success()
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            \Log::error('Employee activation email failed', ['user_id' => $employee->id, 'error' => $e->getMessage()]);
            Notification::make()
                ->title('تم إنشاء الموظف — الإيميل لم يُرسل')
                ->body('انسخ رابط التفعيل التالي وأرسله للموظف عبر واتساب/تلجرام (صالح 72 ساعة): '.$activationUrl)
                ->warning()
                ->persistent()
                ->send();
        }

        return $employee;
    }
}
