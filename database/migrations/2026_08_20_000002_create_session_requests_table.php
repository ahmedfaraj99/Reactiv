<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Just-in-time access approvals for employees. Every employee login
 * creates a `pending` row here; the App panel is blocked for that
 * employee until a manager or supervisor from the same office marks
 * it `approved`. Approvals expire after a configurable window so the
 * employee is re-checked each shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('status', ['pending', 'approved', 'denied', 'revoked', 'expired'])
                ->default('pending');

            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Snapshot at request time — the current values on the User
            // model can change afterward, but for audit we want what was
            // true at approval time.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_fingerprint', 128)->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            // The hot query: "does this user have a currently-valid
            // approval right now?" runs on every request through the
            // middleware, so it must be an index scan not a table scan.
            $table->index(['user_id', 'status', 'expires_at']);
            $table->index(['tenant_id', 'office_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_requests');
    }
};
