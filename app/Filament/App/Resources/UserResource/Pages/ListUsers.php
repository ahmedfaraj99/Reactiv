<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\UserResource\Pages;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource;
use App\Filament\App\Resources\UserResource;
use App\Models\User;
use App\Notifications\UserActivationInvitation;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [Actions\CreateAction::make()];

        // Managers get a shortcut to create an employee directly. They
        // pick the office; every supervisor already covering that office
        // (users with role Supervisor and matching office_id) sees the
        // new employee automatically — no explicit supervisor link is
        // stored on the employee row.
        if (auth()->user()?->isManager()) {
            $actions[] = $this->addEmployeeAction();
        }

        return $actions;
    }

    private function addEmployeeAction(): Actions\Action
    {
        return Actions\Action::make('addEmployee')
            ->label('إضافة موظف')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->modalHeading('إضافة موظف جديد')
            ->modalSubmitActionLabel('إنشاء وإرسال دعوة')
            ->form([
                Forms\Components\TextInput::make('name')
                    ->label('الاسم الكامل')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->required()
                    ->unique(
                        table: 'users',
                        column: 'email',
                        modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at'),
                    ),

                Forms\Components\TextInput::make('phone')
                    ->label('رقم الهاتف')
                    ->tel()
                    ->maxLength(50),

                Forms\Components\Select::make('office_id')
                    ->label('المكتب')
                    ->helperText('كل مشرفي المكتب سيرون هذا الموظف تلقائياً.')
                    ->required()
                    ->searchable()
                    ->options(function (): array {
                        $u = auth()->user();
                        $officeIds = $u?->managedOffices()->pluck('id')->all() ?? [];

                        return \App\Models\Office::query()
                            ->whereIn('id', $officeIds)
                            ->orderBy('name')
                            ->get(['id', 'name'])
                            ->mapWithKeys(function (\App\Models\Office $office): array {
                                $supervisorCount = User::query()
                                    ->where('office_id', $office->id)
                                    ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Supervisor->value))
                                    ->count();

                                $label = $office->name;
                                $label .= $supervisorCount > 0
                                    ? " ({$supervisorCount} مشرف)"
                                    : ' (بلا مشرف)';

                                return [$office->id => $label];
                            })
                            ->all();
                    })
                    ->rule(function () {
                        // Anti-tampering guard: the submitted office_id
                        // must be one of this manager's own offices, no
                        // matter what the client sends.
                        return function (string $attribute, $value, \Closure $fail): void {
                            $u = auth()->user();
                            $officeIds = $u?->managedOffices()->pluck('id')->all() ?? [];
                            if (! in_array((int) $value, $officeIds, true)) {
                                $fail('المكتب المختار خارج نطاق صلاحيتك.');
                            }
                        };
                    }),
            ])
            ->action(function (array $data): void {
                $tenant = filament()->getTenant();
                if ($tenant === null) {
                    return;
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

                $employee = User::create([
                    'tenant_id'         => $tenant->id,
                    'office_id'         => (int) $data['office_id'],
                    'name'              => $data['name'],
                    'email'             => $data['email'],
                    'phone'             => $data['phone'] ?? null,
                    'password'          => Hash::make(Str::random(64)),
                    'active'            => false,
                    'email_verified_at' => null,
                ]);

                $employee->assignRole(UserRole::Employee->value);

                Cache::forget(AccountResource::employeeOptionsCacheKey((int) auth()->id()));

                try {
                    $employee->notify(new UserActivationInvitation);
                    Notification::make()
                        ->title('تم إنشاء الموظف')
                        ->body('تم إرسال رابط تفعيل إلى '.$employee->email.'. صالح 72 ساعة.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    \Log::error('Employee activation email failed', ['user_id' => $employee->id, 'error' => $e->getMessage()]);
                    Notification::make()
                        ->title('تم إنشاء الموظف لكن الإيميل لم يُرسل')
                        ->body('استخدم زر "إعادة إرسال دعوة" من صفحة الموظفين (يظهر عند تسجيل دخول المشرف).')
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }
}
