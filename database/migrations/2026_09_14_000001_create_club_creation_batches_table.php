<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery batches for the standalone "Create EA Accounts" service.
 * Each batch = one link handed to an ad-hoc worker (recipient is a
 * free-text label — a Facebook handle, name, or phone; we deliberately
 * keep no persistent worker record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_creation_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 32)->unique();
            $table->string('recipient', 255);
            $table->unsignedInteger('account_count');
            $table->decimal('price_per_account', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_creation_batches');
    }
};
