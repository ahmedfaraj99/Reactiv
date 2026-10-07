<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Match accounts (activation + play N matches) pay a different flat rate
 * from activation-only accounts. commission_per_activation becomes the
 * activation-only rate; this column is the match-account rate. Seeded
 * from the old single rate so existing totals — every account so far was
 * a match account — don't change until the owner sets the new prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->decimal('commission_per_match_account', 8, 2)->nullable()->after('commission_per_activation');
        });

        DB::table('tenants')->update(['commission_per_match_account' => DB::raw('commission_per_activation')]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('commission_per_match_account');
        });
    }
};
