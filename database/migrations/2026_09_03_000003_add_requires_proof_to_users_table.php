<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Per-employee trust flag for the activation proof requirement.
            // Default true = safer: every new employee needs a proof photo
            // on activation until a manager explicitly whitelists them.
            // Managers who complained about the mandatory flow can flip
            // this off for trusted employees only, while still keeping
            // the guard on for new / less-trusted staff.
            $table->boolean('requires_proof')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('requires_proof');
        });
    }
};
