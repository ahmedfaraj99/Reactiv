<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RevealLog;
use App\Services\AlertEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs AlertEngine's rule checks off the request cycle. Previously this
 * ran synchronously inside the RevealLogObserver, meaning every reveal/
 * TOTP-generate click waited on 2-3 extra COUNT queries before the
 * employee got their response. Queued, it costs the request nothing —
 * as long as a worker (`php artisan queue:work`) is actually running.
 */
class EvaluateAlertRules implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $revealLogId)
    {
    }

    public function handle(AlertEngine $engine): void
    {
        $log = RevealLog::find($this->revealLogId);
        if ($log === null) {
            return;
        }

        $engine->evaluate($log);
    }
}
