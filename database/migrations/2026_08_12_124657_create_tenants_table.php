<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subdomain')->nullable()->unique();
            $table->string('encryption_key_id')->nullable();
            $table->enum('status', ['trial', 'active', 'suspended'])->default('trial');
            $table->enum('plan', ['starter', 'pro', 'enterprise'])->default('starter');
            $table->unsignedInteger('max_accounts')->default(1000);
            $table->unsignedInteger('max_employees')->default(50);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
