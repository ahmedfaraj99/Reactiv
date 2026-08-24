<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use ParagonIE\ConstantTime\Base32;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Result payload returned to the caller after an import run.
 *
 * @phpstan-type ImportError array{row:int,reason:string,email:?string}
 */
class AccountImportService
{
    /**
     * Columns the import expects (case-insensitive header match).
     * ea_email, ea_password, and both backup code headers must be present,
     * but their per-row values may be left blank — a blank ea_email/
     * ea_password means EA shares the PSN one; backup codes are only
     * needed sometimes.
     */
    private const COLUMNS = [
        'email', 'psn_password', 'psn_totp_secret',
        'ea_email', 'ea_password', 'ea_totp_secret',
        'ea_backup_code_1', 'ea_backup_code_2',
    ];

    /**
     * @param  int  $matchesRequired  Applied to every account in this batch — set
     *                                by the owner on the import form when they
     *                                know the whole batch is for a customer who
     *                                bought the "play N matches" add-on. Defaults
     *                                to 0 (activation only). Not per-row: a single
     *                                upload = one customer's tier of service.
     * @return array{imported:int, skipped:int, failed:int, errors:list<array{row:int,reason:string,email:?string}>}
     */
    public function import(UploadedFile $file, Tenant $tenant, User $uploader, User $manager, int $matchesRequired = 0, ?Client $client = null): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            throw new RuntimeException('تعذّرت قراءة الملف المرفوع.');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $reader = match ($ext) {
            'csv'         => new CsvReader(),
            'xlsx', 'xls' => new XlsxReader(),
            default       => throw new RuntimeException('نوع الملف غير مدعوم. استخدم xlsx أو csv.'),
        };

        $reader->open($path);

        // One batch id per import call — stamped on every account this
        // service inserts, so a later "undo last import" can identify
        // exactly the rows to reverse without dragging in unrelated
        // uploads that share timestamp minute or uploader.
        $batchId = (string) Str::uuid();

        $imported = 0;
        $skipped  = 0;
        $failed   = 0;
        $errors   = [];
        $headerMap = null;
        $rowIndex = 0;

        // Enforce the tenant's plan limit — tracked locally instead of a
        // COUNT query per row.
        $accountCount = Account::where('tenant_id', $tenant->id)->count();
        $maxAccounts = $tenant->max_accounts;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowIndex++;
                $cells = array_map(
                    fn ($c) => trim((string) $c->getValue()),
                    $row->getCells(),
                );

                if ($headerMap === null) {
                    $headerMap = $this->mapHeaders($cells);
                    if ($headerMap === null) {
                        $reader->close();
                        throw new RuntimeException(
                            'الصف الأول يجب أن يحتوي على الأعمدة: '.implode(', ', self::COLUMNS)
                        );
                    }
                    continue;
                }

                if ($this->rowIsBlank($cells)) {
                    continue;
                }

                if ($accountCount >= $maxAccounts) {
                    $failed++;
                    $errors[] = [
                        'row'    => $rowIndex,
                        'reason' => "تجاوزت الحد الأقصى للحسابات المسموح به لهذه الشركة ({$maxAccounts}).",
                        'email'  => null,
                    ];
                    continue;
                }

                $data = $this->extract($cells, $headerMap);

                try {
                    $result = $this->save($data, $tenant, $uploader, $manager, $matchesRequired, $batchId, $client);
                    if ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $imported++;
                        $accountCount++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'row'    => $rowIndex,
                        'reason' => $e->getMessage(),
                        'email'  => $data['email'] ?? null,
                    ];
                }
            }
            break; // first sheet only
        }

        $reader->close();

        return compact('imported', 'skipped', 'failed', 'errors');
    }

    /**
     * @param  list<string>  $headers
     * @return array<string,int>|null
     */
    private function mapHeaders(array $headers): ?array
    {
        $map = [];
        foreach ($headers as $i => $h) {
            $normalized = strtolower(trim($h));
            if (in_array($normalized, self::COLUMNS, true)) {
                $map[$normalized] = $i;
            }
        }
        foreach (self::COLUMNS as $required) {
            if (! isset($map[$required])) {
                return null;
            }
        }
        return $map;
    }

    /**
     * @param  list<string>       $cells
     * @param  array<string,int>  $headerMap
     * @return array<string,string>
     */
    private function extract(array $cells, array $headerMap): array
    {
        $out = [];
        foreach ($headerMap as $col => $i) {
            $out[$col] = $cells[$i] ?? '';
        }
        return $out;
    }

    /** @param  list<string>  $cells */
    private function rowIsBlank(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param  array<string,string>  $data
     * @return 'created'|'skipped'
     */
    private function save(array $data, Tenant $tenant, User $uploader, User $manager, int $matchesRequired, string $batchId, ?Client $client = null): string
    {
        $email = $data['email'];
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('البريد غير صالح.');
        }

        // ea_email is optional — blank means EA uses the primary email.
        $eaEmail = $data['ea_email'] !== '' ? $data['ea_email'] : null;
        if ($eaEmail !== null && ! filter_var($eaEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('بريد EA غير صالح.');
        }

        $psnPassword = $data['psn_password'];
        if ($psnPassword === '') {
            throw new RuntimeException('كلمة مرور PSN فارغة.');
        }

        // ea_password is optional — blank means EA uses the PSN password.
        $eaPassword = $data['ea_password'] !== '' ? $data['ea_password'] : null;

        $psnSeed = strtoupper(preg_replace('/\s+/', '', $data['psn_totp_secret']) ?? '');
        if ($psnSeed === '' || ! $this->isValidBase32($psnSeed)) {
            throw new RuntimeException('psn_totp_secret ليس Base32 صالحاً.');
        }

        $eaSeed = strtoupper(preg_replace('/\s+/', '', $data['ea_totp_secret']) ?? '');
        if ($eaSeed === '' || ! $this->isValidBase32($eaSeed)) {
            throw new RuntimeException('ea_totp_secret ليس Base32 صالحاً.');
        }

        // Backup codes are optional, but only make sense as a pair.
        $eaBackupCode1 = $data['ea_backup_code_1'] !== '' ? $data['ea_backup_code_1'] : null;
        $eaBackupCode2 = $data['ea_backup_code_2'] !== '' ? $data['ea_backup_code_2'] : null;
        if (($eaBackupCode1 === null) !== ($eaBackupCode2 === null)) {
            throw new RuntimeException('لازم تدخل كود الاحتياط الأول والثاني معاً، أو تترك الاثنين فارغين.');
        }

        $emailFingerprint = Account::fingerprint($email);
        $eaFingerprint    = $eaEmail !== null ? Account::fingerprint($eaEmail) : null;

        $exists = Account::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($emailFingerprint, $eaFingerprint): void {
                $q->where('email_fingerprint', $emailFingerprint);
                if ($eaFingerprint !== null) {
                    $q->orWhere('ea_email_fingerprint', $eaFingerprint);
                }
            })
            ->exists();

        if ($exists) {
            return 'skipped';
        }

        DB::transaction(function () use (
            $email, $psnPassword, $psnSeed, $emailFingerprint,
            $eaEmail, $eaPassword, $eaSeed, $eaFingerprint,
            $eaBackupCode1, $eaBackupCode2, $matchesRequired, $batchId,
            $tenant, $uploader, $manager, $client,
        ): void {
            Account::create([
                'tenant_id'            => $tenant->id,
                'email'                => $email,
                'email_fingerprint'    => $emailFingerprint,
                'psn_password'         => $psnPassword,
                'psn_totp_seed'        => $psnSeed,
                'ea_email'             => $eaEmail,
                'ea_email_fingerprint' => $eaFingerprint,
                'ea_password'          => $eaPassword,
                'ea_totp_seed'         => $eaSeed,
                'ea_backup_code_1'     => $eaBackupCode1,
                'ea_backup_code_2'     => $eaBackupCode2,
                'matches_required'     => $matchesRequired,
                'status'               => 'available',
                'uploaded_by'          => $uploader->id,
                'manager_id'           => $manager->id,
                'client_id'            => $client?->id,
                'import_batch_id'      => $batchId,
            ]);
        });

        return 'created';
    }

    private function isValidBase32(string $s): bool
    {
        if (! preg_match('/^[A-Z2-7]+=*$/', $s)) {
            return false;
        }
        try {
            Base32::decodeUpper($s);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
