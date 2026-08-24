<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->foreignId('manager_id')->nullable()->after('tenant_id')
                ->constrained('users')->nullOnDelete();
        });

        $this->backfillManagerIdFromSupervisors();
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manager_id');
        });
    }

    /**
     * Every existing supervisor already has an office_id pointing at the
     * one office they manage — copy that into the office's new manager_id
     * so demo/live data keeps working under the new one-manager-many-offices
     * model. If more than one supervisor somehow shares an office_id, the
     * lowest-id supervisor wins; the rest need manual reassignment via the
     * Office form afterward.
     */
    private function backfillManagerIdFromSupervisors(): void
    {
        $supervisorRoleId = DB::table('roles')
            ->where('name', 'supervisor')
            ->where('guard_name', 'web')
            ->value('id');

        if ($supervisorRoleId === null) {
            return;
        }

        $supervisors = DB::table('users')
            ->join('model_has_roles', function ($join) use ($supervisorRoleId): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', 'App\\Models\\User')
                    ->where('model_has_roles.role_id', $supervisorRoleId);
            })
            ->whereNotNull('users.office_id')
            ->orderBy('users.id')
            ->select('users.id', 'users.office_id')
            ->get();

        $claimed = [];
        foreach ($supervisors as $supervisor) {
            if (isset($claimed[$supervisor->office_id])) {
                continue;
            }
            $claimed[$supervisor->office_id] = true;

            DB::table('offices')
                ->where('id', $supervisor->office_id)
                ->update(['manager_id' => $supervisor->id]);
        }
    }
};
