<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partial batches: a customer asks for 5 and finishes 3, or changes
 * their mind and wants 3 before starting. Accounts taken off a link
 * either go straight back to the pool (never touched — no code was
 * generated) or to a new `review` status for the owner to check,
 * since the worker saw the credentials and may have half-done them.
 *
 * - batches.closed_at: owner closed the batch; the link is dead and
 *   it's billed on what was actually done.
 * - accounts.released_from_batch_id: which batch a `review` account
 *   came from, so the owner has context and "done" can be credited
 *   back to that batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('revoked_at');
        });

        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->foreignId('released_from_batch_id')
                ->nullable()
                ->after('batch_id')
                ->constrained('club_creation_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('club_creation_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('released_from_batch_id');
        });

        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->dropColumn('closed_at');
        });
    }
};
