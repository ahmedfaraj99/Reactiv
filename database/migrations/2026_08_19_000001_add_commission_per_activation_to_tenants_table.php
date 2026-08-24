<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable, currency-agnostic — a tenant that hasn't set a rate simply
 * doesn't see payout totals on the commissions report (counts only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->decimal('commission_per_activation', 8, 2)->nullable()->after('max_employees');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('commission_per_activation');
        });
    }
};
