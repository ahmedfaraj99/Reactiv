<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prevent two clients with the same name inside one tenant. Scoped by
 * tenant_id — different tenants can each have a "Ahmed" without colliding.
 * Soft-deleted rows are excluded from the unique constraint (Postgres
 * partial index) so re-adding a client after a purge is possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'name'], 'clients_tenant_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique('clients_tenant_id_name_unique');
        });
    }
};
