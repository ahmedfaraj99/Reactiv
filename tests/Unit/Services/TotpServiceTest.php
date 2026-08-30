<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TotpService;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

/**
 * TotpService is a thin wrapper around otphp — the coverage that matters
 * is (1) the code it returns is the one otphp would produce for the same
 * secret at the same instant, and (2) `remaining` never lies about when
 * the current window rolls over.
 */
class TotpServiceTest extends TestCase
{
    public function test_current_code_matches_otphp_for_the_same_secret(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';

        $result = (new TotpService)->currentCode($secret);

        $expected = TOTP::createFromSecret($secret)->now();
        $this->assertSame($expected, $result['code']);
    }

    public function test_remaining_is_between_1_and_period(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';

        $result = (new TotpService)->currentCode($secret);

        $this->assertGreaterThanOrEqual(1, $result['remaining']);
        $this->assertLessThanOrEqual(30, $result['remaining']);
    }
}
