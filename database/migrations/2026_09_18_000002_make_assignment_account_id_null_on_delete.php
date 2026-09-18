<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve employee completion history when the owner forceDeletes an
 * account. Previously the FK cascaded, so a permanent-delete wiped
 * every assignment row for that account — silently shrinking the
 * employee's "إجمالي مكتمل" stat. Switching to nullOnDelete keeps
 * the assignment row (with account_id = NULL) so the historical
 * record survives, at the cost of every read of $assignment->account
 * needing a null-guard.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
            $table->foreignId('account_id')->nullable()->change();
            $table->foreign('account_id')
                ->references('id')->on('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // We can't restore cascade cleanly if any row already has
        // account_id = NULL, and reverting would silently re-enable
        // the historical-record-erasing behavior. One-way.
    }
};
