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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [Actions\CreateAction::make()];

        // Managers get a shortcut to create an employee directly and
        // pick which supervisor gets them. The employee's office is
        // derived from the chosen supervisor so the office/scoping
        // logic elsewhere keeps working unchanged.
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

                Forms\Components\Select::make('supervisor_id')
                    ->label('المشرف المسؤول')
                    ->helperText('الموظف ينضم لمكتب هذا المشرف تلقائياً.')
                    ->required()
                    ->searchable()
                    ->options(function (): array {
                        $u = auth()->user();
                        $officeIds = $u?->managedOffices()->pluck('id')->all() ?? [];

                        return User::query()
                            ->whereIn('office_id', $officeIds)
                            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Supervisor->value))
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (User $s) => [
                                $s->id => $s->name.' ('.($s->office?->name ?? '—').')',
                            ])
                            ->all();
                    })
                    ->rule(function () {
                        // Same anti-tampering guard as the base UserResource
                        // form uses on office_id: the supervisor id must
                        // resolve to a supervisor actually inside one of
                        // this manager's offices, no matter what the
                        // client submits.
                        return function (string $attribute, $value, \Closure $fail): void {
                            $u = auth()->user();
                            $officeIds = $u?->managedOffices()->pluck('id')->all() ?? [];
                            $ok = User::query()
                                ->where('id', $value)
                                ->whereIn('office_id', $officeIds)
                                ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Supervisor->value))
                                ->exists();
                            if (! $ok) {
                                $fail('المشرف المختار خارج نطاق صلاحيتك.');
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

                $supervisor = User::findOrFail($data['supervisor_id']);

                $employee = User::create([
                    'tenant_id'         => $tenant->id,
                    'office_id'         => $supervisor->office_id,
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
