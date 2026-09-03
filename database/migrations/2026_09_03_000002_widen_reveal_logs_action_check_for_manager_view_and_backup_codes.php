<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Two new audited actions:
        //   - manager_view_credentials: written whenever a manager opens
        //     the credentials modal on one of their batch accounts.
        //   - request_backup_codes: written when an employee submits the
        //     manager-gated request for EA backup codes.
        // Both were dropped by the CHECK constraint in production, 500ing
        // the click. Additive/safe: existing rows keep their values.
        DB::statement('ALTER TABLE reveal_logs DROP CONSTRAINT reveal_logs_action_check');
        DB::statement(
            "ALTER TABLE reveal_logs ADD CONSTRAINT reveal_logs_action_check ".
            "CHECK (action::text = ANY (ARRAY['reveal_credentials','generate_totp','generate_totp_psn','generate_totp_ea','request_totp_approval','manager_view_credentials','request_backup_codes','complete','fail','submit_proof','approve','reject']::text[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reveal_logs DROP CONSTRAINT reveal_logs_action_check');
        DB::statement(
            "ALTER TABLE reveal_logs ADD CONSTRAINT reveal_logs_action_check ".
            "CHECK (action::text = ANY (ARRAY['reveal_credentials','generate_totp','generate_totp_psn','generate_totp_ea','request_totp_approval','complete','fail','submit_proof','approve','reject']::text[]))"
        );
    }
};
