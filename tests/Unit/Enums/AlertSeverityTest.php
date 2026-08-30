<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AlertSeverity;
use PHPUnit\Framework\TestCase;

class AlertSeverityTest extends TestCase
{
    public function test_labels_are_defined_for_every_case(): void
    {
        foreach (AlertSeverity::cases() as $case) {
            $this->assertNotEmpty($case->label(), "missing label for {$case->name}");
        }
    }

    public function test_critical_and_high_share_the_danger_color(): void
    {
        $this->assertSame('danger', AlertSeverity::Critical->filamentColor());
        $this->assertSame('danger', AlertSeverity::High->filamentColor());
    }

    public function test_medium_maps_to_warning_and_low_maps_to_gray(): void
    {
        $this->assertSame('warning', AlertSeverity::Medium->filamentColor());
        $this->assertSame('gray', AlertSeverity::Low->filamentColor());
    }

    public function test_options_returns_value_to_label_map(): void
    {
        $options = AlertSeverity::options();

        $this->assertSame('حرج', $options['critical']);
        $this->assertSame('مرتفع', $options['high']);
        $this->assertCount(count(AlertSeverity::cases()), $options);
    }
}
