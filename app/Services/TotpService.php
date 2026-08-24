<?php

declare(strict_types=1);

namespace App\Services;

use OTPHP\TOTP;

/**
 * Wraps otphp for account TOTP generation. Keeping this behind a service
 * makes it trivial to swap implementations and to mock in tests.
 */
class TotpService
{
    /**
     * @return array{code:string, remaining:int}
     *   remaining = seconds until the current code rolls over
     */
    public function currentCode(string $base32Secret): array
    {
        $totp = TOTP::createFromSecret($base32Secret);
        $code = $totp->now();

        $period = $totp->getPeriod();
        $remaining = $period - (time() % $period);

        return ['code' => $code, 'remaining' => $remaining];
    }
}
