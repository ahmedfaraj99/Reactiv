<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The proof-review workflow logs three new action types to the
        // same append-only audit table. Additive/safe: existing rows keep
        // their current values.
        DB::statement('ALTER TABLE reveal_logs DROP CONSTRAINT reveal_logs_action_check');
        DB::statement(
            "ALTER TABLE reveal_logs ADD CONSTRAINT reveal_logs_action_check ".
            "CHECK (action::text = ANY (ARRAY['reveal_credentials','generate_totp','complete','fail','submit_proof','approve','reject']::text[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reveal_logs DROP CONSTRAINT reveal_logs_action_check');
        DB::statement(
            "ALTER TABLE reveal_logs ADD CONSTRAINT reveal_logs_action_check ".
            "CHECK (action::text = ANY (ARRAY['reveal_credentials','generate_totp','complete','fail']::text[]))"
        );
    }
};
