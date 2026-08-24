<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every account belongs to (at most) one external client. Nullable so
 * accounts uploaded before the End-of-Day workflow existed still load.
 * Set on nulldelete so removing a client doesn't cascade-drop history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('manager_id')
                ->constrained('clients')->nullOnDelete();
            $table->index(['tenant_id', 'client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'client_id', 'status']);
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
