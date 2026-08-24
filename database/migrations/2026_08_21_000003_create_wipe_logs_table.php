<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit of every End-of-Day credential wipe. The whole point of
 * wiping is to shrink blast radius — the wipe itself must be provable and
 * un-tamperable, otherwise a malicious owner could destroy evidence and
 * blame the app. Never cascade-delete these rows; if a tenant is removed,
 * we keep the log so an investigator can still see what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wipe_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('wiped_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('accounts_wiped');
            $table->timestamp('wiped_at')->useCurrent();
            $table->string('ip', 45)->nullable();

            $table->index(['tenant_id', 'wiped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wipe_logs');
    }
};
