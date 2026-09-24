<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Daily activation quota for the performance-monitoring page.
        // The tenant sets one default for everyone; a single employee
        // can be given their own number (new hire ramping up, a proven
        // fast worker…). Both nullable: no default + no override means
        // "no target", and the page just shows raw counts for them.
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedSmallInteger('default_daily_target')->nullable()->after('commission_per_activation');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('daily_target')->nullable()->after('requires_proof');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('daily_target');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('default_daily_target');
        });
    }
};
