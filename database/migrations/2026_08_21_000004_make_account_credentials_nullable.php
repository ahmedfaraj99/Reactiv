<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials must be nullable so End-of-Day Wipe can null them out
 * without deleting the whole row. The row itself stays as the audit
 * trail (email, timestamps, employee) — only the six secret columns
 * clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->text('psn_password')->nullable()->change();
            $table->text('psn_totp_seed')->nullable()->change();
            $table->text('ea_totp_seed')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversed — restoring NOT NULL on a table that now has null
        // rows would fail, and there's no safe default to backfill.
    }
};
