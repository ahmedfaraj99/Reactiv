<?php

use App\Jobs\EscalateOverdueAssignments;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires the server's cron to call `php artisan schedule:run` every
// minute (standard Laravel cron entry) — Schedule::* alone does nothing
// on its own, same as queue:work needing a real worker process.
Schedule::command('backup:clean')->daily()->at('01:30');
Schedule::command('backup:run')->daily()->at('02:00')
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::critical('النسخ الاحتياطي اليومي فشل — راجع الآن.'));
Schedule::command('backup:monitor')->daily()->at('03:00');

// Same "requires a real worker/cron" caveat as the jobs above — this
// dispatches to the queue, so QUEUE_CONNECTION alone isn't enough.
Schedule::job(new EscalateOverdueAssignments)->everyThirtyMinutes();

// Weekly PDF per office, covers the just-closed Mon–Sun week.
Schedule::command('reports:weekly-office')->weeklyOn(0, '06:00');
