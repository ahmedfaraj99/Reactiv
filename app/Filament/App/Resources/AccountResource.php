<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Enums\UserRole;
use App\Filament\App\Resources\AccountResource\Pages;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\RevealLog;
use App\Models\User;
use App\Services\AccountImportService;
use App\Services\RevealRateLimiter;
use App\Services\TotpService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'الحسابات';

    protected static ?string $modelLabel = 'حساب';

    protected static ?string $pluralModelLabel = 'الحسابات';

    protected static ?int $navigationSort = 3;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

    /**
     * The accounts page is view + bulk-action only now (archive/delete for
     * the owner, failed-export for the manager) — no ad-hoc single-record
     * create/edit/delete. Those bypassed the archive system's audit trail
     * entirely, and the create form never had the required credential
     * fields anyway (it would fail on save).
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * The owner uploads a batch for a specific manager (see the import
     * action) — from that point on, only that manager and the
     * supervisors under them can see the batch. The owner sees
     * everything in the tenant for oversight; a manager sees only
     * their own batches; a supervisor sees only their manager's
     * batches. Only the ability to act on a given row is further
     * restricted, via canManage() below.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['assignment.employee', 'uploader', 'manager']);
        $u = auth()->user();

        if ($u?->isManager()) {
            $query->where('manager_id', $u->id);
        } elseif ($u?->isSupervisor()) {
            $query->where('manager_id', $u->office?->manager_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد')
                    ->formatStateUsing(fn (string $state): string => self::maskEmail($state))
                    ->tooltip('البريد مخفي جزئياً — يُكشف فقط للموظف عند التفعيل')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('ea_email')
                    ->label('بريد EA')
                    ->placeholder('— (نفس البريد)')
                    ->formatStateUsing(fn (?string $state): ?string => $state !== null ? self::maskEmail($state) : null)
                    ->tooltip(fn (Account $record): string => $record->ea_email !== null
                        ? 'البريد مخفي جزئياً — يُكشف فقط للموظف عند التفعيل'
                        : 'EA يستخدم نفس البريد الأساسي')
                    ->badge()
                    ->color('warning')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('effective_status')
                    ->label('الحالة')
                    ->badge()
                    ->state(fn (Account $record): string => self::effectiveStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'available'       => 'success',
                        'pending'         => 'gray',
                        'in_progress'     => 'warning',
                        'awaiting_review' => 'warning',
                        'activated', 'completed' => 'info',
                        'failed'          => 'danger',
                        'retired'         => 'gray',
                        default           => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'available'       => 'متاح',
                        'pending'         => 'مخصَّص',
                        'in_progress'     => 'قيد التنفيذ',
                        'awaiting_review' => 'بانتظار المراجعة',
                        'activated', 'completed' => 'مفعَّل',
                        'failed'          => 'فشل',
                        'retired'         => 'مؤرشف',
                        default           => $state,
                    }),

                Tables\Columns\TextColumn::make('assignment.employee.name')
                    ->label('مخصَّص لـ')
                    ->placeholder('—')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('matches_required')
                    ->label('المباريات المطلوبة')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'purple' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.' مباريات' : 'تفعيل فقط')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('uploader.name')
                    ->label('رفعه')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('manager.name')
                    ->label('مدير الدفعة')
                    ->placeholder('—')
                    ->visible(fn (): bool => auth()->user()?->isTenantOwner() ?? false)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('أُنشئ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('downloadTemplate')
                    ->label('تنزيل قالب Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->isTenantOwner() ?? false)
                    ->action(fn () => response()->streamDownload(
                        function (): void {
                            echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel renders Arabic correctly
                            echo implode(',', [
                                'email', 'psn_password', 'psn_totp_secret',
                                'ea_email', 'ea_password', 'ea_totp_secret',
                                'ea_backup_code_1', 'ea_backup_code_2',
                            ])."\n";
                            // Row 1: EA shares PSN's email and password (both left blank), no backup codes — the common case.
                            echo implode(',', [
                                'player1@example.com', 'PsnPass!01', 'JBSWY3DPEHPK3PXP',
                                '', '', 'NB2W45DFOIZA4TZI', '', '',
                            ])."\n";
                            // Row 2: EA has its own email, password, and backup codes — the exceptional case.
                            echo implode(',', [
                                'player2@example.com', 'PsnPass!02', 'NB2W45DFOIZA4TZI',
                                'player2.ea@example.com', 'EaPass!002', 'MFRGGZDFMZTWQ2LK',
                                'AB12-CD34', 'EF56-GH78',
                            ])."\n";
                        },
                        'قالب_استيراد_الحسابات.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    )),

                Tables\Actions\Action::make('import')
                    ->label('رفع حسابات لمدير')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->visible(fn (): bool => auth()->user()?->isTenantOwner() ?? false)
                    ->form([
                        Forms\Components\Select::make('manager_id')
                            ->label('المدير المستهدف')
                            ->helperText('الحسابات المرفوعة ستظهر عنده وعند المشرفين التابعين له فقط.')
                            ->options(function (): array {
                                $tenant = filament()->getTenant();
                                if ($tenant === null) {
                                    return [];
                                }
                                return User::query()
                                    ->where('tenant_id', $tenant->id)
                                    ->where('active', true)
                                    ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Manager->value))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->preload()
                            ->required(),

                        // The external client — the person the batch is FOR
                        // (not the manager who handles it). Grouping activated
                        // accounts by client is what makes end-of-day handoffs
                        // possible. Optional today so legacy uploads still work,
                        // but End-of-Day only surfaces accounts that have one.
                        Forms\Components\Select::make('client_id')
                            ->label('العميل')
                            ->helperText('الشخص أو الجهة التي تخصها هذه الحسابات. اختر عميلاً موجوداً أو أضف جديداً.')
                            ->options(function (): array {
                                $tenant = filament()->getTenant();
                                if ($tenant === null) {
                                    return [];
                                }
                                return \App\Models\Client::query()
                                    ->where('tenant_id', $tenant->id)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم العميل')
                                    ->required()
                                    ->maxLength(255)
                                    ->rule(fn () => \Illuminate\Validation\Rule::unique('clients', 'name')
                                        ->where(fn ($q) => $q->where('tenant_id', filament()->getTenant()?->id ?? 0))
                                        ->whereNull('deleted_at')),
                                Forms\Components\TextInput::make('email')->label('البريد (اختياري)')->email()->maxLength(255),
                                Forms\Components\TextInput::make('phone')->label('الهاتف (اختياري)')->maxLength(40),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                $tenant = filament()->getTenant();
                                abort_if($tenant === null, 403);
                                return \App\Models\Client::create([
                                    'tenant_id' => $tenant->id,
                                    'name'      => $data['name'],
                                    'email'     => $data['email'] ?? null,
                                    'phone'     => $data['phone'] ?? null,
                                ])->id;
                            }),

                        Forms\Components\Placeholder::make('help')
                            ->label('الأعمدة المطلوبة')
                            ->content('email, psn_password, psn_totp_secret, ea_email, ea_password, ea_totp_secret, ea_backup_code_1, ea_backup_code_2 — اترك ea_email أو ea_password فارغاً إن كان EA يستخدم نفس بريد/رمز PSN. أكواد الاحتياط اختيارية، لكن لازم الاثنان معاً أو لا شيء.'),

                        // Applied to every account in this batch. All accounts
                        // in a single upload share the same customer's service
                        // tier, so one setting per batch is the right shape.
                        Forms\Components\Toggle::make('requires_matches')
                            ->label('هل يتطلب لعب مباريات؟')
                            ->helperText('يُطبَّق على كل الحسابات في هذه الدفعة.')
                            ->live()
                            ->default(false),

                        Forms\Components\TextInput::make('matches_required')
                            ->label('عدد المباريات المطلوبة')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(99)
                            ->required(fn (Forms\Get $get): bool => (bool) $get('requires_matches'))
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('requires_matches'))
                            ->default(3),

                        Forms\Components\FileUpload::make('file')
                            ->label('ملف Excel أو CSV')
                            ->required()
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                                'text/plain',
                            ])
                            // Matches public/.user.ini's upload_max_filesize —
                            // raising one without the other just moves where
                            // a large batch gets silently rejected.
                            ->maxSize(20480)
                            ->disk('local')
                            ->directory('imports')
                            ->storeFileNamesIn('original_name'),
                    ])
                    ->action(function (array $data): void {
                        $tenant   = filament()->getTenant();
                        $uploader = auth()->user();
                        $manager  = $tenant !== null
                            ? User::query()->where('tenant_id', $tenant->id)->find($data['manager_id'])
                            : null;
                        $client   = $tenant !== null && ! empty($data['client_id'])
                            ? \App\Models\Client::query()->where('tenant_id', $tenant->id)->find($data['client_id'])
                            : null;

                        if ($tenant === null || $uploader === null || $manager === null || $client === null) {
                            Notification::make()->danger()->title('تعذّرت العملية')->send();
                            return;
                        }

                        $relativePath = (string) $data['file'];
                        $absolute = Storage::disk('local')->path($relativePath);

                        // Wrap the stored file into an UploadedFile so the
                        // service can treat it identically to a fresh upload.
                        $uploaded = new UploadedFile(
                            $absolute,
                            (string) ($data['original_name'] ?? basename($relativePath)),
                            null,
                            null,
                            true,
                        );

                        $matchesRequired = ($data['requires_matches'] ?? false)
                            ? (int) ($data['matches_required'] ?? 0)
                            : 0;

                        try {
                            $result = app(AccountImportService::class)->import(
                                $uploaded,
                                $tenant,
                                $uploader,
                                $manager,
                                $matchesRequired,
                                $client,
                            );
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('فشل الاستيراد')
                                ->body($e->getMessage())
                                ->send();
                            return;
                        } finally {
                            Storage::disk('local')->delete($relativePath);
                        }

                        $body = sprintf(
                            'أُضيف %d — تجاهل %d — فشل %d',
                            $result['imported'],
                            $result['skipped'],
                            $result['failed'],
                        );
                        if (! empty($result['errors'])) {
                            $sample = array_slice($result['errors'], 0, 3);
                            $body .= "\n\nأمثلة أخطاء:";
                            foreach ($sample as $err) {
                                $body .= "\n• صف {$err['row']}: {$err['reason']}";
                            }
                        }

                        Notification::make()
                            ->success($result['failed'] === 0)
                            ->title('اكتمل الاستيراد')
                            ->body($body)
                            ->send();
                    }),

                // Safety-net for a mis-typed matches count, wrong customer's
                // batch, or realising after the fact that ea_password
                // wasn't shared. Only removes the LATEST batch, and only
                // while every account in it is still pristine (no
                // credentials_revealed_at on any assignment). If any row
                // has been touched, the deletion is refused with the
                // exact count so the owner can dig into which one is
                // already in use — never a silent wipe of usage evidence.
                Tables\Actions\Action::make('undoLastImport')
                    ->label('تراجع عن آخر رفع')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (): bool => auth()->user()?->isTenantOwner() ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('تراجع عن آخر عملية رفع')
                    ->modalDescription('يحذف حسابات الدفعة الأخيرة نهائياً بشرط أن لا يكون أي منها قد فُتح من موظف. لا يمكن التراجع.')
                    ->action(function (): void {
                        self::undoLastImport();
                    }),
            ])
            ->actions([
                // The owner uploads a batch FOR a specific manager; the
                // manager may need to inspect an account's credentials to
                // hand them off, help a stuck employee, or verify a client
                // complaint. Scoped to accounts in the manager's own
                // batches, and every open is written to reveal_logs so
                // the audit trail matches the employee's activation view.
                Tables\Actions\Action::make('viewCredentials')
                    ->label('عرض البيانات')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->visible(fn (Account $record): bool => auth()->user()?->isManager()
                        && (int) $record->manager_id === (int) auth()->id())
                    ->modalHeading(fn (Account $record): string => 'بيانات الحساب #'.$record->id)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    // mountUsing runs ONCE when the modal opens; modalContent
                    // is re-invoked on every Livewire re-render (the table
                    // polls every 10s), so any side effect written there
                    // would insert a new RevealLog row on each poll. All
                    // gating + audit + rate limiting lives here; modalContent
                    // stays a pure view render.
                    ->mountUsing(function (Account $record): void {
                        // Emergency freeze — the same kill switch that stops
                        // Activation from revealing credentials must stop this
                        // path too, otherwise the freeze banner lies.
                        if ($record->tenant->isFrozen()) {
                            Notification::make()
                                ->danger()
                                ->title('النظام في وضع الطوارئ')
                                ->body('العرض معلَّق مؤقتاً. تواصل مع المالك.')
                                ->send();
                            abort(423);
                        }

                        // Per-user + per-IP throttle — mirrors what the
                        // employee reveal enforces, so a compromised or
                        // abusive manager can't dump every account's
                        // credentials in a loop.
                        $limiter = app(RevealRateLimiter::class);
                        $reason = $limiter->check(auth()->user(), (string) request()->ip(), 'reveal_credentials');
                        if ($reason !== null) {
                            $limiter->raiseAlert(auth()->user(), 'manager_view_credentials', $reason, $record->id);
                            Notification::make()
                                ->danger()
                                ->title('تم إيقاف العملية مؤقتاً')
                                ->body($reason)
                                ->send();
                            abort(429);
                        }

                        RevealLog::create([
                            'tenant_id'          => $record->tenant_id,
                            'user_id'            => (int) auth()->id(),
                            'account_id'         => $record->id,
                            'action'             => 'manager_view_credentials',
                            'ip'                 => request()->ip(),
                            'user_agent'         => substr((string) request()->userAgent(), 0, 500),
                            'device_fingerprint' => request()->cookie('fc_fp'),
                            'created_at'         => now(),
                        ]);
                    })
                    ->modalContent(function (Account $record): \Illuminate\Contracts\View\View {
                        $totp = app(TotpService::class);

                        // Backup codes are intentionally NOT surfaced here —
                        // they're a manager-gated fallback that only reaches
                        // the employee via an approved BackupCodesReveal
                        // request (see Activation::requestBackupCodesAction).
                        return view('filament.app.resources.account-resource.credentials-modal', [
                            'account'      => $record,
                            'psn_email'    => $record->email,
                            'psn_password' => $record->psn_password,
                            'ea_email'     => $record->effectiveEaEmail(),
                            'ea_password'  => $record->effectiveEaPassword(),
                            'psn_totp'     => $totp->currentCode($record->psn_totp_seed),
                            'ea_totp'      => $totp->currentCode($record->ea_totp_seed),
                            'backup1'      => null,
                            'backup2'      => null,
                        ]);
                    }),

                // Free-text channel between manager/supervisor and the
                // employee working the account — shows up read-only on the
                // employee's "حساباتي" list (see MyAccounts). Shares the
                // same `notes` column the system itself writes to on a
                // wrong-data failure, so a later failure report overwrites
                // a manual note — acceptable for a single free-text field,
                // and consistent with how `notes` already behaved.
                Tables\Actions\Action::make('note')
                    ->label('ملاحظة')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('gray')
                    ->visible(fn (Account $record): bool => $record->assignment !== null && self::canManage($record))
                    ->form([
                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظة للموظف')
                            ->rows(4)
                            ->maxLength(1000),
                    ])
                    ->fillForm(fn (Account $record): array => [
                        'notes' => $record->assignment?->notes,
                    ])
                    ->action(function (Account $record, array $data): void {
                        $record->assignment?->update(['notes' => $data['notes'] !== '' ? $data['notes'] : null]);
                        Notification::make()->success()->title('تم حفظ الملاحظة')->send();
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('effective_status')
                    ->label('الحالة')
                    ->options([
                        'available'       => 'متاح',
                        'assigned'        => 'مخصَّص (لم يبدأ)',
                        'in_progress'     => 'قيد التنفيذ',
                        'awaiting_review' => 'بانتظار المراجعة',
                        'activated'       => 'مفعَّل',
                        'failed'          => 'فشل',
                        'retired'         => 'مؤرشف',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }
                        return match ($value) {
                            'available', 'activated', 'retired' => $query->where('status', $value),
                            'assigned'        => $query->where('status', 'assigned')
                                ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_PENDING)),
                            'in_progress'     => $query->where('status', 'assigned')
                                ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_IN_PROGRESS)),
                            'awaiting_review' => $query->where('status', 'assigned')
                                ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_AWAITING_REVIEW)),
                            'failed'          => $query->where('status', 'assigned')
                                ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_FAILED)),
                            default => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('assigned_employee')
                    ->label('مخصَّص لموظف')
                    ->options(fn (): array => self::employeeOptions())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $employeeId = $data['value'] ?? null;
                        if (! $employeeId) {
                            return $query;
                        }
                        return $query->whereHas('assignment', fn ($a) => $a->where('employee_id', $employeeId));
                    }),

                Tables\Filters\Filter::make('exact_email')
                    ->label('بحث بالبريد الدقيق')
                    ->form([
                        Forms\Components\TextInput::make('email')
                            ->label('البريد (تطابق كامل)')
                            ->email()
                            ->helperText('البريد مشفَّر في القاعدة، فلا يدعم البحث الجزئي — اكتب البريد كاملاً بالضبط.'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $email = trim((string) ($data['email'] ?? ''));
                        if ($email === '') {
                            return $query;
                        }

                        $fingerprint = Account::fingerprint($email);
                        return $query->where(function ($q) use ($fingerprint): void {
                            $q->where('email_fingerprint', $fingerprint)
                                ->orWhere('ea_email_fingerprint', $fingerprint);
                        });
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $email = trim((string) ($data['email'] ?? ''));
                        return $email !== '' ? 'البريد: '.$email : null;
                    }),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Owner-only: hand a batch of existing accounts to a
                    // different manager without re-uploading the Excel.
                    // Covers "wrong manager picked at import", staff
                    // reshuffles, or spreading an oversized batch across
                    // two managers. Only accounts that haven't been
                    // opened yet are moved — a revealed account is
                    // already in an employee's hands under the current
                    // manager's supervisor, and moving it silently would
                    // strand that work. Also wipes the assignment for
                    // moved rows so the new manager's supervisors start
                    // from a clean queue.
                    Tables\Actions\BulkAction::make('assign_to_manager_bulk')
                        ->label('إسناد إلى مدير')
                        ->icon('heroicon-o-user-plus')
                        ->color('primary')
                        ->visible(fn (): bool => auth()->user()?->isTenantOwner() ?? false)
                        ->form([
                            Forms\Components\Select::make('manager_id')
                                ->label('المدير المستهدف')
                                ->helperText('الحسابات المحددة ستنتقل إلى هذا المدير والمشرفين التابعين له فقط.')
                                ->options(function (): array {
                                    $tenant = filament()->getTenant();
                                    if ($tenant === null) {
                                        return [];
                                    }
                                    return User::query()
                                        ->where('tenant_id', $tenant->id)
                                        ->where('active', true)
                                        ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Manager->value))
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all();
                                })
                                ->searchable()
                                ->preload()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $tenant = filament()->getTenant();
                            if ($tenant === null) {
                                return;
                            }

                            $managerId = (int) $data['manager_id'];
                            $manager = User::query()
                                ->where('tenant_id', $tenant->id)
                                ->where('active', true)
                                ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Manager->value))
                                ->find($managerId);
                            if ($manager === null) {
                                Notification::make()->danger()->title('المدير غير موجود')->send();
                                return;
                            }

                            // Re-load the selected accounts scoped to THIS
                            // tenant — the Livewire payload is client-
                            // controlled and could smuggle account ids from
                            // another tenant. Filament's list scoping
                            // catches this on the display query, not on the
                            // bulk-action Collection parameter.
                            $ids = $records->pluck('id')->all();
                            $scoped = Account::query()
                                ->with('assignment')
                                ->where('tenant_id', $tenant->id)
                                ->whereIn('id', $ids)
                                ->get();

                            // Only reassign truly-pending rows. An
                            // assignment counts as "in flight" if it's
                            // past PENDING (in_progress / awaiting_review
                            // / completed / failed) — deleting those would
                            // erase real work (proof, TOTP counts, notes).
                            $movable = $scoped->filter(function (Account $a): bool {
                                $assignment = $a->assignment;
                                return $assignment === null
                                    || $assignment->status === AccountAssignment::STATUS_PENDING;
                            });
                            $skipped = $records->count() - $movable->count();

                            if ($movable->isEmpty()) {
                                Notification::make()->warning()->title('لا يوجد حسابات قابلة للنقل (كلها قيد التنفيذ)')->send();
                                return;
                            }

                            $actorId = (int) auth()->id();

                            DB::transaction(function () use ($movable, $managerId, $actorId): void {
                                foreach ($movable as $account) {
                                    $account->update(['manager_id' => $managerId]);
                                    // Assignment (if any) belongs to a
                                    // supervisor under the OLD manager;
                                    // deleting it drops the account back
                                    // to `available` under the new one.
                                    // Only PENDING assignments reach here
                                    // (see filter above), so no real work
                                    // is being erased.
                                    if ($account->assignment !== null) {
                                        $account->assignment->delete();
                                        $account->update(['status' => 'available']);
                                    }

                                    \App\Models\AccountAdminLog::create([
                                        'tenant_id'            => $account->tenant_id,
                                        'actor_id'             => $actorId,
                                        'account_id'           => $account->id,
                                        'account_email_masked' => self::maskEmail($account->email),
                                        'action'               => \App\Models\AccountAdminLog::ACTION_REASSIGNED_MANAGER,
                                        'created_at'           => now(),
                                    ]);
                                }
                            });

                            Notification::make()
                                ->success()
                                ->title('أُسند '.$movable->count().' حساباً إلى '.$manager->name)
                                ->body($skipped > 0 ? "تُجُوهل {$skipped} حساباً قيد التنفيذ" : null)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Fast redistribution for the everyday case: an
                    // employee is out, or a supervisor wants to spread
                    // a big batch across the whole team in one shot.
                    // Only touches rows that haven't been opened yet —
                    // once credentials have been revealed to an
                    // employee, moving the account without their
                    // knowledge would strand them mid-activation and
                    // leak the account to a second person.
                    Tables\Actions\BulkAction::make('reassign_bulk')
                        ->label('إعادة تخصيص')
                        ->icon('heroicon-o-user-group')
                        ->color('primary')
                        ->visible(fn (): bool => auth()->user()?->isManager() || auth()->user()?->isSupervisor())
                        ->form([
                            Forms\Components\Radio::make('mode')
                                ->label('طريقة التوزيع')
                                ->options([
                                    'single' => 'كل الحسابات المحددة لموظف واحد',
                                    'split'  => 'توزيع بالتساوي على عدة موظفين',
                                ])
                                ->default('single')
                                ->live()
                                ->required(),

                            Forms\Components\Select::make('employee_id')
                                ->label('الموظف')
                                ->options(fn (): array => self::employeeOptions())
                                ->searchable()
                                ->preload()
                                ->visible(fn (Forms\Get $get): bool => $get('mode') === 'single')
                                ->required(fn (Forms\Get $get): bool => $get('mode') === 'single'),

                            Forms\Components\Select::make('employee_ids')
                                ->label('الموظفون')
                                ->helperText('سيُقسم عدد الحسابات المحددة عليهم بالتساوي (الباقي يتوزع من الأول للأخير).')
                                ->options(fn (): array => self::employeeOptions())
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->minItems(2)
                                ->visible(fn (Forms\Get $get): bool => $get('mode') === 'split')
                                ->required(fn (Forms\Get $get): bool => $get('mode') === 'split'),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            // Filter to rows the caller may act on AND
                            // that aren't already in flight. "in flight"
                            // = credentials revealed. Anything else
                            // (available, or assigned-but-not-yet-opened)
                            // is fair game to move.
                            $movable = $records->filter(function (Account $a): bool {
                                if (! self::canManage($a)) {
                                    return false;
                                }
                                $assignment = $a->assignment;
                                if ($assignment === null) {
                                    return true;
                                }
                                return $assignment->credentials_revealed_at === null;
                            })->values();

                            $skipped = $records->count() - $movable->count();

                            if ($movable->isEmpty()) {
                                Notification::make()->warning()->title('لا يوجد حسابات قابلة لإعادة التخصيص في التحديد')->send();
                                return;
                            }

                            $summary = [];

                            if (($data['mode'] ?? 'single') === 'single') {
                                $employeeId = (int) $data['employee_id'];
                                self::assignAccounts($movable, $employeeId);
                                $summary[] = (User::find($employeeId)?->name ?? '#'.$employeeId).': '.$movable->count();
                            } else {
                                $employeeIds = array_values(array_map('intval', $data['employee_ids'] ?? []));
                                $n = count($employeeIds);
                                $total = $movable->count();
                                $share = intdiv($total, $n);
                                $remainder = $total % $n;

                                $offset = 0;
                                foreach ($employeeIds as $i => $employeeId) {
                                    $take = $share + ($i < $remainder ? 1 : 0);
                                    if ($take <= 0) {
                                        continue;
                                    }
                                    $chunk = $movable->slice($offset, $take);
                                    $offset += $take;
                                    self::assignAccounts($chunk, $employeeId);
                                    $summary[] = (User::find($employeeId)?->name ?? '#'.$employeeId).': '.$chunk->count();
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('اكتملت إعادة التخصيص')
                                ->body(implode(' — ', $summary).($skipped > 0 ? " (تُجُوهل {$skipped} خارج نطاقك أو قيد التنفيذ)" : ''))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Only the manager (of supervisors) handles the client
                    // correction loop — they pull the failed batch and send
                    // it to the owner as Excel outside the system.
                    Tables\Actions\BulkAction::make('export_failed_bulk')
                        ->label('تصدير الأخطاء للعميل وحذفها')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('danger')
                        ->visible(fn (): bool => auth()->user()?->isManager() ?? false)
                        ->requiresConfirmation()
                        ->modalDescription('يُصدَّر ملف Excel بنفس صيغة الاستيراد ليصحّحه العميل، ثم تُحذف الحسابات المُصدَّرة نهائياً من النظام (بما فيها سجل الكشف الخاص بها). لا يمكن التراجع.')
                        ->action(function (Collection $records) {
                            $failed = $records->filter(fn (Account $r): bool => self::effectiveStatus($r) === AccountAssignment::STATUS_FAILED
                                && self::canManage($r));
                            $skipped = $records->count() - $failed->count();

                            if ($failed->isEmpty()) {
                                Notification::make()->warning()->title('لا يوجد حسابات بحالة "فشل" ضمن نطاقك في التحديد')->send();
                                return null;
                            }

                            $csv = self::buildFailedExportCsv($failed);
                            $actorId = auth()->id();

                            DB::transaction(function () use ($failed, $actorId): void {
                                foreach ($failed as $account) {
                                    // Log BEFORE deleting — same reasoning
                                    // as the owner's permanent-delete flow:
                                    // the account row (and its cascaded
                                    // reveal_logs) is gone right after this.
                                    \App\Models\AccountAdminLog::create([
                                        'tenant_id'            => $account->tenant_id,
                                        'actor_id'             => $actorId,
                                        'account_id'           => $account->id,
                                        'account_email_masked' => self::maskEmail($account->email),
                                        'action'               => \App\Models\AccountAdminLog::ACTION_PERMANENTLY_DELETED,
                                        'created_at'           => now(),
                                    ]);
                                    $account->forceDelete();
                                }
                            });

                            Notification::make()
                                ->success()
                                ->title('صُدِّر وحُذف '.$failed->count().' حساباً'.($skipped > 0 ? '، تُجُوهل '.$skipped.' خارج نطاقك أو غير فاشل' : ''))
                                ->send();

                            return response()->streamDownload(
                                fn () => print($csv),
                                'حسابات_للتصحيح_'.now()->format('Y-m-d_His').'.csv',
                                ['Content-Type' => 'text/csv; charset=UTF-8'],
                            );
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Owner-only, two steps for protection: archive first
                    // (reversible), permanently erase later (not).
                    Tables\Actions\BulkAction::make('archive_completed_bulk')
                        ->label('أرشفة الحسابات المكتملة')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->visible(fn (): bool => auth()->user()?->isTenantOwner() ?? false)
                        ->requiresConfirmation()
                        ->modalDescription('يُخفى الحساب من القوائم النشطة. تقدر ترجعه لاحقاً، أو تمسحه نهائياً من تبويب "مؤرشف".')
                        ->action(function (Collection $records): void {
                            $completed = $records->filter(fn (Account $r): bool => $r->status === 'activated');
                            $skipped = $records->count() - $completed->count();
                            $actorId = auth()->id();

                            DB::transaction(function () use ($completed, $actorId): void {
                                foreach ($completed as $account) {
                                    $account->update(['status' => 'retired']);
                                    \App\Models\AccountAdminLog::create([
                                        'tenant_id'            => $account->tenant_id,
                                        'actor_id'             => $actorId,
                                        'account_id'           => $account->id,
                                        'account_email_masked' => self::maskEmail($account->email),
                                        'action'               => \App\Models\AccountAdminLog::ACTION_ARCHIVED,
                                        'created_at'           => now(),
                                    ]);
                                }
                            });

                            Notification::make()
                                ->success()
                                ->title('أُرشف '.$completed->count().' حساباً'.($skipped > 0 ? '، تُجُوهل '.$skipped.' غير مكتمل' : ''))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('delete_archived_bulk')
                        ->label('مسح نهائي من النظام')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (): bool => auth()->user()?->isTenantOwner() ?? false)
                        ->requiresConfirmation()
                        ->modalDescription('حذف نهائي لا رجعة فيه — يشمل كل بيانات الحساب وسجل الكشف الخاص به. متاح فقط للحسابات المؤرشفة.')
                        ->action(function (Collection $records): void {
                            $archived = $records->filter(fn (Account $r): bool => $r->status === 'retired');
                            $skipped = $records->count() - $archived->count();
                            $actorId = auth()->id();

                            DB::transaction(function () use ($archived, $actorId): void {
                                foreach ($archived as $account) {
                                    // Log BEFORE deleting — the account row
                                    // (and its cascaded reveal_logs) will be
                                    // gone right after this.
                                    \App\Models\AccountAdminLog::create([
                                        'tenant_id'            => $account->tenant_id,
                                        'actor_id'             => $actorId,
                                        'account_id'           => $account->id,
                                        'account_email_masked' => self::maskEmail($account->email),
                                        'action'               => \App\Models\AccountAdminLog::ACTION_PERMANENTLY_DELETED,
                                        'created_at'           => now(),
                                    ]);
                                    $account->forceDelete();
                                }
                            });

                            Notification::make()
                                ->success()
                                ->title('مُسح نهائياً '.$archived->count().' حساباً'.($skipped > 0 ? '، تُجُوهل '.$skipped.' غير مؤرشف' : ''))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            // Live progress for the manager/supervisor watching activation
            // happen in real time — the tab badges (available/failed/
            // awaiting review/completed) and the rows both refresh on
            // their own instead of needing a manual page reload.
            ->poll('10s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
        ];
    }

    /**
     * List of employees the current user is allowed to assign accounts to:
     *  - Owner: any employee in the tenant
     *  - Manager: employees in the offices they manage
     *  - Supervisor: employees in their single office
     *
     * @return array<int,string>
     */
    public static function employeeOptions(): array
    {
        $u = auth()->user();
        $tenant = filament()->getTenant();
        if ($u === null || $tenant === null) {
            return [];
        }

        // This gets queried repeatedly per page load (filters, the
        // distribute-accounts picker, the exposure report) and rarely
        // changes — a short TTL keyed per-user cuts the repeat query
        // without risking a stale list for long. Employee create/
        // deactivate/delete also bust it immediately (see below and
        // UserObserver), so this is just a safety-net window, not the
        // only source of truth.
        return Cache::remember(
            self::employeeOptionsCacheKey($u->id),
            30,
            function () use ($u, $tenant): array {
                $query = User::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('active', true)
                    ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value));

                if ($u->isManager()) {
                    $query->whereIn('office_id', self::managedOfficeIds($u));
                } elseif ($u->isSupervisor()) {
                    $query->where('office_id', $u->office_id);
                }

                return $query->orderBy('name')->pluck('name', 'id')->all();
            },
        );
    }

    public static function employeeOptionsCacheKey(int $userId): string
    {
        return "account_resource:employee_options:{$userId}";
    }

    /**
     * True only if the given user id is a valid assignment target for the
     * currently-authenticated supervisor/manager: same tenant, active, has
     * the Employee role, and inside the caller's office scope. The picker
     * options honor these constraints already — this backs them up so a
     * hand-crafted Livewire payload cannot smuggle an out-of-scope id (or
     * cross-tenant id) into an AccountAssignment row.
     */
    public static function isValidAssignmentTarget(int $employeeId): bool
    {
        $u = auth()->user();
        $tenant = filament()->getTenant();
        if ($u === null || $tenant === null) {
            return false;
        }

        $query = User::query()
            ->where('id', $employeeId)
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value));

        if ($u->isManager()) {
            $query->whereIn('office_id', self::managedOfficeIds($u));
        } elseif ($u->isSupervisor()) {
            $query->where('office_id', $u->office_id);
        } else {
            return false;
        }

        return $query->exists();
    }

    /**
     * IDs of the offices a manager runs. Cached per-request so canManage()
     * doesn't re-query per row when iterating a Collection in bulk actions.
     *
     * @var array<int,array<int,int>>
     */
    private static array $managedOfficeIdsCache = [];

    /**
     * @return array<int,int>
     */
    private static function managedOfficeIds(User $u): array
    {
        return self::$managedOfficeIdsCache[$u->id] ??= $u->managedOffices()->pluck('id')->all();
    }

    /**
     * Whether the current user may edit/delete/assign this specific account.
     * Everyone (owner/manager/supervisor) can SEE every account in the
     * tenant, but only the following can ACT on a row:
     *   - Owner: any account
     *   - Manager: unassigned OR assigned to an employee in a managed office
     *   - Supervisor: unassigned OR assigned to an employee in their office
     */
    private static function canManage(Account $account): bool
    {
        $u = auth()->user();
        if ($u === null) {
            return false;
        }
        if ($u->isTenantOwner()) {
            return true;
        }

        $employee = $account->assignment?->employee;

        if ($u->isManager()) {
            return $employee === null || in_array($employee->office_id, self::managedOfficeIds($u), true);
        }

        if ($u->isSupervisor()) {
            return $employee === null || $employee->office_id === $u->office_id;
        }

        return false;
    }

    /**
     * Reverse the most recent import batch for the current tenant —
     * find the last batch id, verify none of its accounts have been
     * touched (any assignment with credentials_revealed_at set means a
     * real employee has seen it, so we must not erase the evidence),
     * then force-delete the accounts and log it. All-or-nothing: the
     * whole batch reverses or none of it does.
     */
    private static function undoLastImport(): void
    {
        $tenant = filament()->getTenant();
        if ($tenant === null) {
            return;
        }

        // Order by id (monotonic per-insert) rather than created_at —
        // multiple rows in the same microsecond tie on created_at and
        // give a non-deterministic "latest". id is guaranteed distinct.
        $latest = Account::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('import_batch_id')
            ->latest('id')
            ->first();

        if ($latest === null || $latest->import_batch_id === null) {
            Notification::make()->warning()->title('لا يوجد رفع سابق يمكن التراجع عنه')->send();
            return;
        }

        $batchId = $latest->import_batch_id;

        $batchAccounts = Account::query()
            ->with('assignment')
            ->where('tenant_id', $tenant->id)
            ->where('import_batch_id', $batchId)
            ->get();

        $touched = $batchAccounts->filter(fn (Account $a): bool =>
            $a->assignment !== null && $a->assignment->credentials_revealed_at !== null
        );

        if ($touched->isNotEmpty()) {
            Notification::make()
                ->danger()
                ->title('تعذّر التراجع')
                ->body(sprintf(
                    'الدفعة الأخيرة (%d حساب) تحتوي على %d حساب فُتح من موظف — لا يمكن حذف دليل الاستخدام. احذفها فردياً بعد المراجعة.',
                    $batchAccounts->count(),
                    $touched->count(),
                ))
                ->persistent()
                ->send();
            return;
        }

        $actorId = auth()->id();
        $count = $batchAccounts->count();

        DB::transaction(function () use ($batchAccounts, $actorId): void {
            foreach ($batchAccounts as $account) {
                \App\Models\AccountAdminLog::create([
                    'tenant_id'            => $account->tenant_id,
                    'actor_id'             => $actorId,
                    'account_id'           => $account->id,
                    'account_email_masked' => self::maskEmail($account->email),
                    'action'               => \App\Models\AccountAdminLog::ACTION_PERMANENTLY_DELETED,
                    'created_at'           => now(),
                ]);
                $account->forceDelete();
            }
        });

        Notification::make()
            ->success()
            ->title("تم التراجع عن {$count} حساب")
            ->body('حُذفت الدفعة نهائياً. السجل محفوظ في "سجل الأرشفة والحذف".')
            ->send();
    }

    /**
     * Wrap the reassignment in a single transaction — the unique constraint
     * on account_assignments.account_id guards against a race where two
     * supervisors assign the same account at once.
     *
     * @param  \Illuminate\Support\Collection<int,Account>  $accounts
     */
    public static function assignAccounts($accounts, int $employeeId): void
    {
        $supervisor = auth()->user();
        $tenantId = filament()->getTenant()?->id;
        if ($supervisor === null || $tenantId === null) {
            return;
        }

        // Refuse forged employee ids — the picker restricts to valid targets
        // but the Livewire payload is client-controlled, so re-check here.
        // Otherwise a hand-crafted request would let a supervisor in tenant
        // A hand credentials to a user in tenant B (see #1 in the audit).
        abort_unless(self::isValidAssignmentTarget($employeeId), 403);

        DB::transaction(function () use ($accounts, $employeeId, $supervisor, $tenantId): void {
            foreach ($accounts as $account) {
                AccountAssignment::updateOrCreate(
                    ['account_id' => $account->id],
                    [
                        'tenant_id'     => $tenantId,
                        'employee_id'   => $employeeId,
                        'supervisor_id' => $supervisor->id,
                        'status'        => 'pending',
                        'assigned_at'   => now(),
                        // Wipe out anything left from a previous (e.g.
                        // failed) attempt so the new employee starts clean —
                        // otherwise they'd inherit a used-up 2FA allowance,
                        // stale notes, or an old rejection reason.
                        'started_at'              => null,
                        'completed_at'            => null,
                        'notes'                   => null,
                        'proof_path'              => null,
                        'submitted_at'            => null,
                        'reviewed_by'             => null,
                        'reviewed_at'             => null,
                        'rejection_reason'        => null,
                        'psn_totp_generations'    => 0,
                        'ea_totp_generations'     => 0,
                        'psn_totp_extra_allowed'  => 0,
                        'ea_totp_extra_allowed'   => 0,
                    ],
                );
                $account->update(['status' => 'assigned']);
            }
        });
    }

    /**
     * CSV in the exact import format, plus one extra "سبب الخطأ" column
     * so the client sees what to fix. Extra columns don't break re-import
     * — the importer only looks for the columns it needs by name.
     *
     * @param  \Illuminate\Support\Collection<int,Account>  $accounts
     */
    private static function buildFailedExportCsv($accounts): string
    {
        // A hand-built implode(',', ...) silently corrupts the file the
        // moment any value (a password, a rejection note) contains a
        // comma or newline — Writer quotes/escapes fields properly.
        $csv = \League\Csv\Writer::fromString('');
        $csv->setOutputBOM(\League\Csv\Bom::Utf8);

        $csv->insertOne([
            'email', 'psn_password', 'psn_totp_secret',
            'ea_email', 'ea_password', 'ea_totp_secret',
            'ea_backup_code_1', 'ea_backup_code_2', 'سبب_الخطأ',
        ]);

        foreach ($accounts as $account) {
            $csv->insertOne([
                $account->email,
                $account->psn_password,
                $account->psn_totp_seed,
                $account->ea_email ?? '',
                $account->ea_password ?? '',
                $account->ea_totp_seed,
                $account->ea_backup_code_1 ?? '',
                $account->ea_backup_code_2 ?? '',
                $account->assignment?->notes ?? '',
            ]);
        }

        return $csv->toString();
    }

    /**
     * Preserve the first 3 and last 2 characters of the local part so two
     * accounts sharing a prefix (e.g. demo-psn-1 / demo-ea-2) don't render
     * identically. Very short local parts fall back to a single-char reveal.
     */
    public static function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $len = mb_strlen($local);

        if ($len <= 4) {
            return mb_substr($local, 0, 1).str_repeat('*', max(1, $len - 1)).'@'.$domain;
        }

        $prefix     = mb_substr($local, 0, 3);
        $suffix     = mb_substr($local, -2);
        $middle     = str_repeat('*', max(3, $len - 5));
        return $prefix.$middle.$suffix.'@'.$domain;
    }

    /**
     * Derived status: for `assigned` accounts, surface the assignment's
     * own status so users can distinguish "just assigned" from "in flight"
     * from "awaiting review" from "failed" — all of which currently share
     * account.status = assigned.
     */
    private static function effectiveStatus(Account $account): string
    {
        if ($account->status !== 'assigned') {
            return $account->status;
        }
        return $account->assignment?->status ?? 'assigned';
    }

    /**
     * A retire toggle should not fire while an assignment is actively in
     * flight (pending / in progress / awaiting review). Once the assignment
     * is completed or failed — or if the account was never assigned at all
     * — retiring is safe.
     */
    private static function canRetire(Account $account): bool
    {
        if ($account->status !== 'assigned') {
            return true;
        }

        $assignmentStatus = $account->assignment?->status;
        return in_array($assignmentStatus, [
            AccountAssignment::STATUS_COMPLETED,
            AccountAssignment::STATUS_FAILED,
            null,
        ], true);
    }
}
