<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // Some EA accounts need backup codes on top of the TOTP seed —
            // used only sometimes, hence nullable. Fixed at 2 codes, which
            // matches how EA actually issues them. Encrypted via model
            // casts, hence TEXT.
            $table->text('ea_backup_code_1')->nullable()->after('ea_totp_seed');
            $table->text('ea_backup_code_2')->nullable()->after('ea_backup_code_1');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['ea_backup_code_1', 'ea_backup_code_2']);
        });
    }
};
