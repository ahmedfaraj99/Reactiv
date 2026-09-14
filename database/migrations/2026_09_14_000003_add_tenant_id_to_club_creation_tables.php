<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope Club Creation records to a tenant so this service lives inside
 * the tenant owner's own /app panel rather than the SuperAdmin panel.
 * Nullable at the column level so the earlier rows created during
 * initial testing don't fail the migration; the app enforces
 * non-null via the create paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
