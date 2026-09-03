<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            // Backup codes are a fallback for EA when TOTP misbehaves. They
            // must never be revealed to the employee automatically — a
            // supervisor or the manager over their office decides case-by-
            // case whether to open them, same shape as the TOTP over-limit
            // approval. This timestamp is null until that approval lands.
            $table->timestamp('ea_backup_codes_approved_at')->nullable()->after('ea_totp_extra_allowed');
            $table->foreignId('ea_backup_codes_approved_by')->nullable()->after('ea_backup_codes_approved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ea_backup_codes_approved_by');
            $table->dropColumn('ea_backup_codes_approved_at');
        });
    }
};
