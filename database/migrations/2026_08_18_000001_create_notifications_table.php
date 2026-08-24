<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard notifications table — backs the `database` channel
 * used by CriticalAlertNotification so the in-app bell in Filament's
 * topbar has somewhere to read from, alongside the existing mail channel.
 *
 * `data` uses jsonb (not text — Laravel's default): Filament's bell
 * component filters with `data->>'format' = 'filament'`, which requires
 * a real JSON column on Postgres. A follow-up migration
 * (2026_08_19_000004) ALTERs the column on databases that were created
 * against the old text definition; fresh installs land here directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
