<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertSeverity: string
{
    case Critical = 'critical';
    case High     = 'high';
    case Medium   = 'medium';
    case Low      = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'حرج',
            self::High     => 'مرتفع',
            self::Medium   => 'متوسط',
            self::Low      => 'منخفض',
        };
    }

    public function filamentColor(): string
    {
        return match ($this) {
            self::Critical, self::High => 'danger',
            self::Medium               => 'warning',
            self::Low                  => 'gray',
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
