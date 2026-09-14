<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the IP and User-Agent that opened the delivery link for the
 * first time. Helps the owner rebut "the link never reached me"
 * claims and spot cases where the link was shared to a device other
 * than the intended recipient's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->string('first_open_ip', 45)->nullable()->after('opened_at');
            $table->string('first_open_ua', 255)->nullable()->after('first_open_ip');
        });
    }

    public function down(): void
    {
        Schema::table('club_creation_batches', function (Blueprint $table): void {
            $table->dropColumn(['first_open_ip', 'first_open_ua']);
        });
    }
};
