<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * canAccessPanel() now blocks any user with email_verified_at IS NULL,
 * because that's the marker the new invitation-email flow uses to say
 * "hasn't clicked the activation link yet". Users created before that
 * change never went through the flow, so backfill them to now() —
 * otherwise everyone already using the system would get locked out.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Deliberately empty: we don't know which rows were backfilled vs
        // which were verified organically, and blanking the whole column
        // would lock every user out again.
    }
};
