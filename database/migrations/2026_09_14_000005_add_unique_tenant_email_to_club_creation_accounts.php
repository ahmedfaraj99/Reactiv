<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce (tenant_id, email) uniqueness on club_creation_accounts at
 * the database level so the check-then-insert path in the importer
 * can't race under concurrent uploads. Cleans up any pre-existing
 * duplicates first (keeping the earliest row per tenant+email) so the
 * unique index doesn't fail to create on rollout.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop duplicates so index creation succeeds. Keep the row
        // with the smallest id per (tenant_id, email); soft-delete
        // the rest — they're not force-deleted so we can still audit
        // what was removed.
        $dupes = DB::table('club_creation_accounts')
            ->select('tenant_id', 'email', DB::raw('MIN(id) as keeper_id'))
            ->whereNull('deleted_at')
            ->groupBy('tenant_id', 'email')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $d) {
            DB::table('club_creation_accounts')
                ->where('tenant_id', $d->tenant_id)
                ->where('email', $d->email)
                ->where('id', '!=', $d->keeper_id)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);
        }

        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'email'], 'club_accounts_tenant_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->dropUnique('club_accounts_tenant_email_unique');
        });
    }
};
