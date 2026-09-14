<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-side controls for delivery links: `revoked_at` lets the owner
 * kill a link without deleting the batch (history preserved), and
 * `expires_at` sets an optional wall-clock deadline after which the
 * link stops resolving. Both nullable; a null in either means the
 * link has no lifetime restriction from that dimension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable()->after('opened_at');
            $table->timestamp('expires_at')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->dropColumn(['revoked_at', 'expires_at']);
        });
    }
};
