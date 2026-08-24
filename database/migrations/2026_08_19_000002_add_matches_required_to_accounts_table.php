<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some customers buy activation only; others buy activation + N matches
 * on the account. The owner declares this per-account at upload time
 * (the only person who knows what the customer paid for) — the employee
 * then sees exactly how many matches to play, and the proof requirements
 * flex accordingly. Default 0 = activation only, preserving the old
 * behaviour for every existing row and any import that omits the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('matches_required')->default(0)->after('ea_totp_seed');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('matches_required');
        });
    }
};
