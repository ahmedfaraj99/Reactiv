<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Models\Alert;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Every security-relevant alert (login attacks, new device, suspicious
 * speed, duplicate proof, emergency freeze) must land as a structured
 * line on the `security` log channel — that's the audit trail a SIEM or
 * an oncall grep depends on. Ops noise (overdue, released, off-hours,
 * high-volume, TOTP requests, repeat reveal) must NOT pollute it.
 */
class SecurityAuditLogTest extends TestCase
{
    /** @var array<int, array{level:string, message:string, context:array}> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        $captured = &$this->captured;
        $psrLogger = new class ($captured) implements LoggerInterface {
            use \Psr\Log\LoggerTrait;
            /** @param array<int,mixed> $captured */
            public function __construct(private array &$captured) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->captured[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        Log::shouldReceive('channel')->with('security')->andReturn($psrLogger);
        Log::shouldReceive('channel')->andReturnUsing(fn ($name = null) => Log::getFacadeRoot()->channel($name));
    }

    public function test_a_login_attack_alert_is_written_to_the_security_channel(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant, UserRole::TenantOwner);

        Alert::create([
            'tenant_id' => $tenant->id,
            'type'      => AlertType::LoginAttack,
            'severity'  => AlertSeverity::Critical,
            'message'   => 'محاولات دخول مشبوهة',
            'payload'   => ['ip' => '1.2.3.4'],
        ]);

        $this->assertCount(1, $this->captured);
        $record = $this->captured[0];
        $this->assertSame('info', $record['level']);
        $this->assertSame('alert.raised', $record['message']);
        $this->assertSame('login_attack', $record['context']['type']);
        $this->assertSame('1.2.3.4', $record['context']['payload']['ip']);
    }

    public function test_operational_noise_alerts_do_not_pollute_the_security_channel(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant, UserRole::TenantOwner);

        foreach ([AlertType::AssignmentOverdue, AlertType::HighVolume, AlertType::RepeatReveal, AlertType::TotpLimit, AlertType::OffHours] as $type) {
            Alert::create([
                'tenant_id' => $tenant->id,
                'type'      => $type,
                'severity'  => AlertSeverity::High,
                'message'   => 'noise: '.$type->value,
            ]);
        }

        $this->assertSame([], $this->captured);
    }
}
