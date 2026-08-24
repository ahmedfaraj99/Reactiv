<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Store the extra owner fields in memory before Filament strips them
     * from the create payload (they are marked dehydrated(false)).
     *
     * @var array<string, mixed>
     */
    protected array $ownerPayload = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->ownerPayload = [
            'name'     => $this->data['owner_name'] ?? null,
            'email'    => $this->data['owner_email'] ?? null,
            'phone'    => $this->data['owner_phone'] ?? null,
            'password' => $this->data['owner_password'] ?? null,
        ];

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Tenant {
            /** @var Tenant $tenant */
            $tenant = static::getModel()::create($data);

            $owner = User::create([
                'tenant_id' => $tenant->id,
                'name'      => (string) $this->ownerPayload['name'],
                'email'     => (string) $this->ownerPayload['email'],
                'phone'     => $this->ownerPayload['phone'] ?? null,
                'password'  => Hash::make((string) $this->ownerPayload['password']),
                'active'    => true,
            ]);

            $owner->assignRole(UserRole::TenantOwner->value);

            return $tenant;
        });
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('تم إنشاء الشركة والمالك بنجاح')
            ->body('يستطيع المالك الآن تسجيل الدخول من /app/login وإعداد 2FA.');
    }
}
