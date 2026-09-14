<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Console-account rows now carry two backup codes alongside the
 * primary credentials — one EA backup code and one PSN backup code —
 * which the worker needs during the Create-Club flow. Nullable so
 * pre-existing rows without codes still load; the app enforces
 * their presence on new uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->string('ea_backup_code', 64)->nullable()->after('password');
            $table->string('psn_backup_code', 64)->nullable()->after('ea_backup_code');
        });
    }

    public function down(): void
    {
        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->dropColumn(['ea_backup_code', 'psn_backup_code']);
        });
    }
};
