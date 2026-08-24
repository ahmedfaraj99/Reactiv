<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reveal_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->enum('action', ['reveal_credentials', 'generate_totp', 'complete', 'fail']);

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_fingerprint', 128)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['account_id', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reveal_logs');
    }
};
