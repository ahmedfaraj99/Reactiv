<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHA-256 (hex) of the ORIGINAL uploaded proof bytes — computed BEFORE
 * the server-side watermark is applied, so identical originals produce
 * the same hash regardless of the per-upload watermark. Indexed
 * per-tenant so duplicate lookups on submission cost one seek.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->string('proof_hash', 64)->nullable()->after('proof_path');
            $table->index(['tenant_id', 'proof_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'proof_hash']);
            $table->dropColumn('proof_hash');
        });
    }
};
