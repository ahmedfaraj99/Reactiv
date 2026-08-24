<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->string('proof_path')->nullable()->after('notes');
            $table->timestamp('submitted_at')->nullable()->after('completed_at');
            $table->foreignId('reviewed_by')->nullable()->after('submitted_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
        });

        // Widen the status check constraint to add 'awaiting_review'. Existing
        // rows keep their current values — all remain valid members of the
        // new superset, so this is additive/safe against live data.
        DB::statement('ALTER TABLE account_assignments DROP CONSTRAINT account_assignments_status_check');
        DB::statement(
            "ALTER TABLE account_assignments ADD CONSTRAINT account_assignments_status_check ".
            "CHECK (status::text = ANY (ARRAY['pending','in_progress','awaiting_review','completed','failed']::text[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE account_assignments DROP CONSTRAINT account_assignments_status_check');
        DB::statement(
            "ALTER TABLE account_assignments ADD CONSTRAINT account_assignments_status_check ".
            "CHECK (status::text = ANY (ARRAY['pending','in_progress','completed','failed']::text[]))"
        );

        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['proof_path', 'submitted_at', 'reviewed_at', 'rejection_reason']);
        });
    }
};
