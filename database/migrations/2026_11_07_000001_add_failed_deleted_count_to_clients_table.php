<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed accounts are exported back to the client and permanently deleted,
 * which also severs their link to the client. This keeps a running count
 * per client so those failures stay visible. It starts at zero: failures
 * deleted before this migration can't be attributed to a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedInteger('failed_deleted_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('failed_deleted_count');
        });
    }
};
