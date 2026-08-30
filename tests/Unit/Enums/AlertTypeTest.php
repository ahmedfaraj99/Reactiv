<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AlertType;
use PHPUnit\Framework\TestCase;

class AlertTypeTest extends TestCase
{
    public function test_labels_are_defined_for_every_case(): void
    {
        foreach (AlertType::cases() as $case) {
            $this->assertNotEmpty($case->label(), "missing label for {$case->name}");
        }
    }

    public function test_backing_values_are_snake_case(): void
    {
        foreach (AlertType::cases() as $case) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $case->value,
                "{$case->name} backing value must be snake_case (was '{$case->value}')"
            );
        }
    }

    public function test_options_returns_a_map_usable_by_filament(): void
    {
        $options = AlertType::options();

        $this->assertSame(count(AlertType::cases()), count($options));
        $this->assertSame('محاولات دخول مشبوهة', $options['login_attack']);
        $this->assertSame('صورة إثبات مكررة', $options['duplicate_proof']);
    }
}
