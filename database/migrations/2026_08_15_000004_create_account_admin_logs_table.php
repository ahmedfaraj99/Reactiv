<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_admin_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // account_id is deliberately NOT a foreign key — this log's whole
            // purpose is to prove an archive/delete happened, so it must
            // survive the account being permanently erased. The masked
            // email is stored alongside so the entry stays readable even
            // after the account row is gone.
            $table->unsignedBigInteger('account_id');
            $table->string('account_email_masked');

            $table->string('action', 32); // archived | unarchived | permanently_deleted
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_admin_logs');
    }
};
