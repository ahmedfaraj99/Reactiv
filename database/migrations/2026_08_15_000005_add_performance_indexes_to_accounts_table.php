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
            // Every account list view sorts by created_at within a
            // tenant+status scope (ListAccounts tabs, DistributeAccounts'
            // FIFO pick of available accounts). Measured with EXPLAIN
            // ANALYZE at 2000+ rows: without this, Postgres does a full
            // sequential scan + sort every time — this composite index
            // lets it use an index scan instead, which matters more as
            // the account count grows.
            $table->index(['tenant_id', 'status', 'created_at'], 'accounts_tenant_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex('accounts_tenant_status_created_idx');
        });
    }
};
