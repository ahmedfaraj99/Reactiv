<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The employee's own scratch note per account ("on PS5 #2, played
        // 1 match…") so they can keep track when juggling several consoles.
        // Separate from `notes`, which belongs to the supervisor and is
        // overwritten by the system on a wrong-data failure.
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->text('employee_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('account_assignments', function (Blueprint $table): void {
            $table->dropColumn('employee_notes');
        });
    }
};
