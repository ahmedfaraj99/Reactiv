<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforce (tenant_id, email) uniqueness on live club_creation_accounts
 * rows at the database level so the check-then-insert path in the
 * importer can't race under concurrent uploads.
 *
 * Uses a Postgres PARTIAL unique index scoped to `deleted_at IS NULL`
 * so soft-deleted duplicates (kept for audit) don't conflict with the
 * live row that replaced them. Cleans up any pre-existing live
 * duplicates first by soft-deleting all but the earliest row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Keep the row with the smallest id per (tenant_id, email);
        // soft-delete the rest so the audit trail is preserved.
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

        DB::statement(
            'CREATE UNIQUE INDEX club_accounts_tenant_email_unique '
            .'ON club_creation_accounts (tenant_id, email) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS club_accounts_tenant_email_unique');
    }
};
