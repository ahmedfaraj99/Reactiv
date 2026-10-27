<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A short neutral code per client (C01, C02 …) so managers and
 * supervisors can tell clients apart while distributing without ever
 * seeing the owner's client names. Existing clients are numbered in
 * creation order, per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('code', 20)->nullable()->after('name');
        });

        $next = [];
        DB::table('clients')->orderBy('id')->get(['id', 'tenant_id'])->each(function ($c) use (&$next): void {
            $n = $next[$c->tenant_id] = ($next[$c->tenant_id] ?? 0) + 1;
            DB::table('clients')->where('id', $c->id)->update(['code' => 'C'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)]);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'code'], 'clients_tenant_id_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique('clients_tenant_id_code_unique');
            $table->dropColumn('code');
        });
    }
};
