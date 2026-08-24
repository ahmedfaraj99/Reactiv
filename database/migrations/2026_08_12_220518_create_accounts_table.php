<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // An FC account is one shared email plus a separate PSN 2FA
            // seed and a separate EA 2FA seed. The EA email and EA password
            // only differ from the PSN ones in some cases — both are
            // nullable and, when null, the PSN value is used for EA too.
            // Sensitive fields are encrypted via model casts, hence TEXT.
            $table->text('email');
            $table->string('email_fingerprint', 64);

            $table->text('psn_password');
            $table->text('psn_totp_seed');

            $table->text('ea_email')->nullable();
            $table->string('ea_email_fingerprint', 64)->nullable();
            $table->text('ea_password')->nullable();
            $table->text('ea_totp_seed');

            $table->enum('status', ['available', 'assigned', 'activated', 'retired'])
                ->default('available');

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'email_fingerprint']);
            $table->unique(['tenant_id', 'ea_email_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
