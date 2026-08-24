<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case TenantOwner = 'tenant_owner';
    case Manager = 'manager';
    case Supervisor = 'supervisor';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'مدير النظام',
            self::TenantOwner => 'مالك الشركة',
            self::Manager => 'مدير',
            self::Supervisor => 'مشرف مكتب',
            self::Employee => 'موظف',
        };
    }

    public static function all(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }

    public static function tenantRoles(): array
    {
        return [
            self::TenantOwner->value,
            self::Manager->value,
            self::Supervisor->value,
            self::Employee->value,
        ];
    }
}
