<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-inserts 2000 fake accounts for manager training, all tagged with a
 * single import_batch_id so they can be wiped in one command afterwards:
 *
 *   Account::where('import_batch_id', '<printed-batch-id>')->forceDelete();
 */
class TrainingAccountsSeeder extends Seeder
{
    private const COUNT = 2000;

    // Any valid Base32 string works — training accounts do not need real 2FA.
    private const TOTP_SEED = 'JBSWY3DPEHPK3PXP';

    public function run(): void
    {
        // Pick the tenant to seed under: env override wins, then 'main',
        // then 'demo', then the first tenant that exists. Keeps the seeder
        // portable between production ('main') and local dev ('demo').
        $slug = env('TRAINING_TENANT_SLUG');
        $tenant = $slug
            ? Tenant::where('slug', $slug)->firstOrFail()
            : (Tenant::where('slug', 'main')->first()
                ?? Tenant::where('slug', 'demo')->first()
                ?? Tenant::orderBy('id')->firstOrFail());

        $uploader = User::where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->firstOrFail();

        $batchId = (string) Str::uuid();
        $emailPrefix = 'training-'.now()->format('Ymd-His');

        $this->command?->info("Seeding ".self::COUNT." training accounts under batch [{$batchId}] (email prefix: {$emailPrefix})...");

        DB::transaction(function () use ($tenant, $uploader, $batchId, $emailPrefix): void {
            foreach (range(1, self::COUNT) as $i) {
                Account::create([
                    'tenant_id'        => $tenant->id,
                    'email'            => sprintf('%s-%04d@training.local', $emailPrefix, $i),
                    'psn_password'     => 'PsnPass!'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'psn_totp_seed'    => self::TOTP_SEED,
                    'ea_email'         => null,
                    'ea_password'      => 'EaPass!'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'ea_totp_seed'     => self::TOTP_SEED,
                    'status'           => 'available',
                    'uploaded_by'      => $uploader->id,
                    'matches_required' => 0,
                    'import_batch_id'  => $batchId,
                ]);

                if ($i % 200 === 0) {
                    $this->command?->info("  inserted {$i}/".self::COUNT);
                }
            }
        });

        $this->command?->info("Done. Batch id: {$batchId}");
        $this->command?->warn("To wipe later, run: php artisan tinker --execute=\"App\\Models\\Account::where('import_batch_id','{$batchId}')->forceDelete();\"");
    }
}
