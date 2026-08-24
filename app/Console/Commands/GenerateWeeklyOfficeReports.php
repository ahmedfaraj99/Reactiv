<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Office;
use App\Services\WeeklyOfficeReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a PDF summary per active office for the ISO week ending
 * before the run. Idempotent by (week, office) — re-running overwrites
 * so a correction cycle is safe.
 *
 * Schedule: Sunday 06:00 (covers the just-closed Mon–Sun week).
 */
class GenerateWeeklyOfficeReports extends Command
{
    protected $signature = 'reports:weekly-office
        {--office= : Only run for this office id (id, not name)}
        {--week= : ISO date inside the target week (default: last week)}';

    protected $description = 'Generate the weekly PDF activity report for every active office.';

    public function handle(WeeklyOfficeReport $builder): int
    {
        $reference = $this->option('week')
            ? CarbonImmutable::parse((string) $this->option('week'))
            : CarbonImmutable::now()->subWeek();

        $query = Office::query()->where('active', true);
        if ($officeId = $this->option('office')) {
            $query->whereKey($officeId);
        }

        $count = 0;
        foreach ($query->get() as $office) {
            $data = $builder->build($office, $reference);

            $pdf = Pdf::loadView('reports.weekly_office', $data)
                ->setPaper('a4', 'portrait');

            $dir  = trim((string) config('fc27ac.weekly_report_path'), '/');
            $path = sprintf(
                '%s/%s/office-%d.pdf',
                $dir,
                $data['period']['iso_week'],
                $office->id,
            );

            Storage::put($path, $pdf->output());
            $this->info("wrote {$path}");
            $count++;
        }

        $this->info("Generated {$count} report(s).");
        return self::SUCCESS;
    }
}
