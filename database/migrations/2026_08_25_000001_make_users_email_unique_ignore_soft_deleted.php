<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * With SoftDeletes on User, a plain UNIQUE(email) blocks re-adding an
 * email a manager just deleted — the deleted row still holds the slot.
 * Swap the full unique for a partial unique on non-deleted rows so an
 * email is only "taken" while there's a live user using it. The old
 * deleted row stays for audit/history (RevealLog etc. reference it).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel names the auto-created unique index "users_email_unique".
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users(email) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
    }
};
