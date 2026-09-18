<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Employees now work 24/7, so the off_hours alert rule is gone. Any
 * historical rows with type='off_hours' would fail to hydrate into
 * the AlertType enum once the case is removed — sweep them out here.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('alerts')->where('type', 'off_hours')->delete();
    }

    public function down(): void
    {
        // One-way: the removed rows carry no semantic value once the
        // rule is gone, and the enum case they referenced no longer
        // exists to hydrate them back into.
    }
};
