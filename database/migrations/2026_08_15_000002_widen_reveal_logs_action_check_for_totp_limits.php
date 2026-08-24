<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Splitting TOTP generation into per-platform actions (for the
        // new generation-limit feature) needs two new log actions, plus
        // one for when an employee hits the limit and asks a supervisor
        // to approve more. Additive/safe: existing rows keep their values.
        DB::statement('ALTER TABLE reveal_logs DROP CONSTRAINT reveal_logs_action_check');
        DB::statement(
            "ALTER TABLE reveal_logs ADD CONSTRAINT reveal_logs_action_check ".
            "CHECK (action::text = ANY (ARRAY['reveal_credentials','generate_totp','generate_totp_psn','generate_totp_ea','request_totp_approval','complete','fail','submit_proof','approve','reject']::text[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reveal_logs DROP CONSTRAINT reveal_logs_action_check');
        DB::statement(
            "ALTER TABLE reveal_logs ADD CONSTRAINT reveal_logs_action_check ".
            "CHECK (action::text = ANY (ARRAY['reveal_credentials','generate_totp','complete','fail','submit_proof','approve','reject']::text[]))"
        );
    }
};
