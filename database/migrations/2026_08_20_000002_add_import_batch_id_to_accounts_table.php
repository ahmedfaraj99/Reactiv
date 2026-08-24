<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamps every account with a batch id shared by all rows from the same
 * import call, so an "undo last import" action can identify the accounts
 * to reverse without dragging in unrelated uploads from other batches
 * that happen to share timestamp minute or uploader. Nullable — old rows
 * predating this column and any account not created via import (unlikely
 * given the resource is import-only, but still) simply have null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->uuid('import_batch_id')->nullable()->after('manager_id');
            $table->index(['tenant_id', 'import_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'import_batch_id']);
            $table->dropColumn('import_batch_id');
        });
    }
};
