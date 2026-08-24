<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The initial notifications migration (2026_08_18_000001) followed
 * Laravel's default and stored `data` as TEXT. Filament's own
 * DatabaseNotifications component filters with `data->>'format' =
 * 'filament'`, which in Postgres requires jsonb (the `->>` operator
 * doesn't exist on plain text). Bell rendering blew up on
 * `Undefined function: 7 ERROR: operator does not exist: text ->> unknown`.
 * ALTER-in-place with USING is safe: every row already contains valid
 * JSON (Laravel writes it as JSON), Postgres just doesn't know that
 * until we cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
