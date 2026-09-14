<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Console-account inventory for the "Create EA Accounts" service.
 * Deliberately separate from the primary `accounts` table used by the
 * activation workflow — no shared columns, no FK, no observers in
 * common. The password lives in plaintext because it's a credential
 * we hand back to the end worker via the public delivery link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_creation_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255);
            $table->string('password', 255);
            // available → assigned → done → exported
            $table->string('status', 20)->default('available');
            $table->foreignId('batch_id')
                ->nullable()
                ->constrained('club_creation_batches')
                ->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_creation_accounts');
    }
};
