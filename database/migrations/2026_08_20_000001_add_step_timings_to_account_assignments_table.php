<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fine-grained step timestamps so we can measure how long each phase of
 * an activation actually took, per employee — not the wall time from
 * assign to complete, which includes waiting for the supervisor and
 * doesn't reflect the employee's own work.
 *
 * credentials_revealed_at — set on first mount of the activation page
 *                           (when the employee actually SAW the creds).
 * first_totp_at           — set the first time either PSN or EA TOTP is
 *                           generated for this assignment.
 *
 * submitted_at already exists — that's the natural "employee finished"
 * marker. The three points together let us derive read-to-2FA,
 * 2FA-to-proof, and reveal-to-proof durations without another table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->timestamp('credentials_revealed_at')->nullable()->after('started_at');
            $table->timestamp('first_totp_at')->nullable()->after('credentials_revealed_at');
        });
    }

    public function down(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->dropColumn(['credentials_revealed_at', 'first_totp_at']);
        });
    }
};
