<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertType: string
{
    case RepeatReveal        = 'repeat_reveal';
    case NewDevice           = 'new_device';
    case OffHours            = 'off_hours';
    case HighVolume          = 'high_volume';
    case TotpLimit           = 'totp_limit';
    case LoginAttack         = 'login_attack';
    case AssignmentOverdue   = 'assignment_overdue';
    case AssignmentsReleased = 'assignments_released';
    case DuplicateProof      = 'duplicate_proof';
    case SuspiciousSpeed     = 'suspicious_speed';
    case EmergencyFreeze     = 'emergency_freeze';

    public function label(): string
    {
        return match ($this) {
            self::RepeatReveal        => 'كشف متكرر',
            self::NewDevice           => 'جهاز جديد',
            self::OffHours            => 'خارج ساعات العمل',
            self::HighVolume          => 'حجم غير طبيعي',
            self::TotpLimit           => 'طلب كود إضافي',
            self::LoginAttack         => 'محاولات دخول مشبوهة',
            self::AssignmentOverdue   => 'تخصيص متأخر',
            self::AssignmentsReleased => 'حسابات محررة تحتاج إعادة توزيع',
            self::DuplicateProof      => 'صورة إثبات مكررة',
            self::SuspiciousSpeed     => 'تفعيل بسرعة غير طبيعية',
            self::EmergencyFreeze     => 'تجميد/فك تجميد الطوارئ',
        };
    }

    /** @return array<string,string> value => Arabic label, for Filament ->options() */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
