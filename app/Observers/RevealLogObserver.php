<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\EvaluateAlertRules;
use App\Models\RevealLog;

class RevealLogObserver
{
    public function created(RevealLog $log): void
    {
        EvaluateAlertRules::dispatch($log->id);
    }
}
