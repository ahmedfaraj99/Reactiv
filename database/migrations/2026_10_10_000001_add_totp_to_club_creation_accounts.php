<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Club Creation uploads now carry the full console bundle:
 * EMAIL, PW, EA PW, GAUTH, EA GAUTH — for PSN or Xbox accounts.
 * `email`/`password`/`totp_seed` are the console (PSN or Xbox) login.
 * The worker on the public link only ever sees the console email +
 * password and generates the console code on demand,
 * capped per account like the activation flow; past the cap they send
 * an exception request that only the tenant owner can approve
 * (totp_requested_at = pending, cleared on approve/reject). The EA
 * password and seed are stored for the owner, never delivered.
 *
 * Seeds are stored encrypted (cast on the model) — text, because the
 * ciphertext is far longer than the seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            // psn | xbox — same five columns either way; drives the labels
            // on the public page and which pool a batch draws from.
            $table->string('platform', 10)->default('psn')->after('tenant_id');
            $table->string('ea_password', 255)->nullable()->after('password');
            $table->text('totp_seed')->nullable()->after('ea_password');
            $table->text('ea_totp_seed')->nullable()->after('totp_seed');
            $table->unsignedInteger('totp_generations')->default(0)->after('ea_totp_seed');
            $table->unsignedInteger('totp_extra_allowed')->default(0)->after('totp_generations');
            $table->timestamp('totp_requested_at')->nullable()->after('totp_extra_allowed');
            $table->timestamp('first_totp_at')->nullable()->after('totp_requested_at');

            $table->index('totp_requested_at');
            $table->index(['tenant_id', 'platform', 'status']);
        });

        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->string('platform', 10)->default('psn')->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->dropColumn('platform');
        });

        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->dropIndex(['totp_requested_at']);
            $table->dropIndex(['tenant_id', 'platform', 'status']);
            $table->dropColumn([
                'platform', 'ea_password', 'totp_seed', 'ea_totp_seed',
                'totp_generations', 'totp_extra_allowed',
                'totp_requested_at', 'first_totp_at',
            ]);
        });
    }
};
