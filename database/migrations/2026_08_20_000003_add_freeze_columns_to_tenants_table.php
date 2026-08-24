<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kill-switch state for the tenant: when frozen_at is set, every
 * sensitive action (credential reveal, TOTP generation) refuses to
 * run and every user in the tenant sees a red banner. Rare use —
 * the point is a 1-click stop when a leak/chargeback/breach is
 * suspected, without redeploying or editing config. Owner-only,
 * always reversible: setting frozen_at = null lifts the freeze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('frozen_at')->nullable()->after('commission_per_activation');
            $table->string('frozen_reason', 500)->nullable()->after('frozen_at');
            $table->foreignId('frozen_by')->nullable()->after('frozen_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('frozen_by');
            $table->dropColumn(['frozen_at', 'frozen_reason']);
        });
    }
};
