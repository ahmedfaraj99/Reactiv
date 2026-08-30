<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a dedup_key so callers that raise the same kind of alert repeatedly
 * (login_attack from one IP, new_device for one user+fingerprint, duplicate
 * proof matching one hash, …) can collapse the run into a single OPEN row
 * that gets bumped instead of a mail flood.
 *
 * Uniqueness is enforced in Alert::raise() under a transaction + row lock
 * rather than a partial unique index, because MySQL doesn't support partial
 * indexes portably. The plain composite index below is enough to make the
 * SELECT fast under contention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->string('dedup_key', 128)->nullable()->after('payload');
            $table->index(['tenant_id', 'dedup_key', 'resolved'], 'alerts_dedup_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropIndex('alerts_dedup_lookup_idx');
            $table->dropColumn('dedup_key');
        });
    }
};
