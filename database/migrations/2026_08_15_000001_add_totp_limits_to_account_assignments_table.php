<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            // Caps how many times an employee can pull a fresh 2FA code
            // per platform — enough for the real activation flow (1 PSN
            // confirmation, 2 EA confirmations), no more. Once the base
            // limit plus any supervisor-granted allowance is used up,
            // further generation is blocked until a supervisor approves
            // more via an alert.
            $table->unsignedInteger('psn_totp_generations')->default(0)->after('notes');
            $table->unsignedInteger('ea_totp_generations')->default(0)->after('psn_totp_generations');
            $table->unsignedInteger('psn_totp_extra_allowed')->default(0)->after('ea_totp_generations');
            $table->unsignedInteger('ea_totp_extra_allowed')->default(0)->after('psn_totp_extra_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->dropColumn([
                'psn_totp_generations', 'ea_totp_generations',
                'psn_totp_extra_allowed', 'ea_totp_extra_allowed',
            ]);
        });
    }
};
