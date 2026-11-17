<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PSN backup codes, mirroring the EA pair. Some accounts arrive with no
 * PSN 2FA seed at all and only backup codes, so these double as the
 * sole second factor in that case. Encrypted via model casts, hence TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->text('psn_backup_code_1')->nullable()->after('psn_totp_seed');
            $table->text('psn_backup_code_2')->nullable()->after('psn_backup_code_1');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['psn_backup_code_1', 'psn_backup_code_2']);
        });
    }
};
