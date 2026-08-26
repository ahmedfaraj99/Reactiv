<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived announcement banner an office manager or supervisor pins
 * to the top of every page for the people under them — e.g. "we're
 * down for two hours of maintenance". Auto-hides once expires_at
 * passes, so a stale banner can't sit there forever. Deliberately not
 * a chat log — one banner per office at a time is the intended shape;
 * a new one supersedes the old on the same office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // NULL = tenant-wide (all offices). Owner-only capability.
            $table->foreignId('office_id')->nullable()->constrained('offices')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('message', 500);
            // 'info' (blue), 'warning' (amber), 'danger' (rose) — visual
            // urgency, not access control. Same rendering tokens as the
            // rest of the app's notification chrome.
            $table->string('level', 20)->default('info');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The banner query filters by tenant + office + expiry every
            // page load for every user, so an index on the fields it
            // scans is worth having from day one.
            $table->index(['tenant_id', 'office_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_broadcasts');
    }
};
