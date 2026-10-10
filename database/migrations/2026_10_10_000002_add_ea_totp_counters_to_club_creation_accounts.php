<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ultimate Team asks for the EA verification code too, so the public
 * link generates the EA code alongside the console one. EA gets its
 * own allowance and its own pending-request flag — same as activation,
 * where PSN and EA are gated separately. The EA password itself is
 * still never delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->unsignedInteger('ea_totp_generations')->default(0)->after('first_totp_at');
            $table->unsignedInteger('ea_totp_extra_allowed')->default(0)->after('ea_totp_generations');
            $table->timestamp('ea_totp_requested_at')->nullable()->after('ea_totp_extra_allowed');

            $table->index('ea_totp_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->dropIndex(['ea_totp_requested_at']);
            $table->dropColumn(['ea_totp_generations', 'ea_totp_extra_allowed', 'ea_totp_requested_at']);
        });
    }
};
