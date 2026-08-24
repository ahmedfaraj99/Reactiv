<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // The owner uploads a batch for one specific manager — that
            // manager's supervisors are the only ones who should see it
            // in the pool before distribution. Nullable so accounts
            // imported before this column existed don't break; set for
            // every new import going forward.
            $table->foreignId('manager_id')->nullable()->after('uploaded_by')
                ->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'manager_id']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manager_id');
        });
    }
};
