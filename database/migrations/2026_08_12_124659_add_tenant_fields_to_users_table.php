<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Null for super_admin (system-wide), required for all other roles
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('office_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();

            $table->string('phone')->nullable()->after('email');
            $table->text('google2fa_secret')->nullable()->after('password'); // encrypted via cast
            $table->boolean('google2fa_enabled')->default(false)->after('google2fa_secret');
            $table->string('device_fingerprint')->nullable()->after('google2fa_enabled');
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();

            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['office_id']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'tenant_id', 'office_id', 'phone',
                'google2fa_secret', 'google2fa_enabled',
                'device_fingerprint', 'last_login_ip', 'last_login_at', 'active',
            ]);
        });
    }
};
