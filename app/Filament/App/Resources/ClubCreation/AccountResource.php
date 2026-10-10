<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ClubCreation;

use App\Enums\UserRole;
use App\Filament\App\Resources\ClubCreation\AccountResource\Pages;
use App\Models\ClubCreation\Account;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use ParagonIE\ConstantTime\Base32;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'إنشاء حسابات EA';

    protected static ?string $navigationLabel = 'الحسابات';

    protected static ?string $modelLabel = 'حساب';

    protected static ?string $pluralModelLabel = 'الحسابات';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::TenantOwner->value) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Belt-and-braces tenant scoping. Filament's panel-level
        // tenant() usually handles this, but the owner-only nature
        // of this feature makes an explicit scope cheap and audit-
        // friendly.
        $tenantId = Filament::getTenant()?->id;

        return parent::getEloquentQuery()->when(
            $tenantId,
            fn (Builder $q) => $q->where('tenant_id', $tenantId),
        );
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('platform')
                ->label('المنصة')
                ->options(Account::PLATFORMS)
                ->default(Account::PLATFORM_PSN)
                ->required()
                ->native(false),

            Forms\Components\TextInput::make('email')
                ->label('بريد الكونسول')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('password')
                ->label('رمز الكونسول')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('ea_password')
                ->label('EA PW')
                ->maxLength(255),

            // Seeds are $hidden on the model, so the edit modal never
            // prefills them — blank means "keep the stored one".
            Forms\Components\TextInput::make('totp_seed')
                ->label('GAUTH الكونسول')
                ->placeholder('اتركه فارغاً للإبقاء على المفتاح الحالي')
                ->rule(fn () => self::base32Rule())
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->dehydrateStateUsing(fn (?string $state): ?string => self::normalizeSeed($state)),

            Forms\Components\TextInput::make('ea_totp_seed')
                ->label('EA GAUTH')
                ->placeholder('اتركه فارغاً للإبقاء على المفتاح الحالي')
                ->rule(fn () => self::base32Rule())
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->dehydrateStateUsing(fn (?string $state): ?string => self::normalizeSeed($state)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),

                Tables\Columns\TextColumn::make('platform')
                    ->label('المنصة')
                    ->badge()
                    ->color(fn (string $state): string => $state === Account::PLATFORM_XBOX ? 'success' : 'info')
                    ->formatStateUsing(fn (string $state): string => Account::PLATFORMS[$state] ?? $state),

                Tables\Columns\TextColumn::make('email')
                    ->label('بريد الكونسول')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('password')
                    ->label('رمز الكونسول')
                    ->copyable()
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('ea_password')
                    ->label('EA PW')
                    ->placeholder('—')
                    ->copyable()
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('totp_generations')
                    ->label('أكواد مولّدة')
                    ->formatStateUsing(fn (Account $r): string => $r->totp_generations.'/'.$r->totpAllowance())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Account::STATUS_AVAILABLE => 'gray',
                        Account::STATUS_ASSIGNED  => 'warning',
                        Account::STATUS_DONE      => 'success',
                        Account::STATUS_EXPORTED  => 'info',
                        default                   => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Account::STATUS_AVAILABLE => 'متاح',
                        Account::STATUS_ASSIGNED  => 'مُسنَد',
                        Account::STATUS_DONE      => 'مكتمل',
                        Account::STATUS_EXPORTED  => 'مُصدَّر',
                        default                   => $state,
                    }),

                Tables\Columns\TextColumn::make('batch.recipient')
                    ->label('الزبون')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('done_at')
                    ->label('تاريخ الإنجاز')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الرفع')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->label('المنصة')
                    ->options(Account::PLATFORMS),

                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        Account::STATUS_AVAILABLE => 'متاح',
                        Account::STATUS_ASSIGNED  => 'مُسنَد',
                        Account::STATUS_DONE      => 'مكتمل',
                        Account::STATUS_EXPORTED  => 'مُصدَّر',
                    ]),
            ])
            ->headerActions([
                self::templateActions('downloadTemplate'),

                Tables\Actions\Action::make('bulkUpload')
                    ->label('رفع دفعة حسابات')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Placeholder::make('instructions')
                            ->label('تعليمات')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<div class="text-sm space-y-1 leading-6">'
                                . '<div>• الملف يجب أن يكون Excel (.xlsx) أو CSV (.csv).</div>'
                                . '<div>• الأعمدة بالترتيب: <b>EMAIL</b>, <b>PW</b>, <b>EA PW</b>, <b>GAUTH</b>, <b>EA GAUTH</b> — نفس الترتيب لـ PlayStation و Xbox.</div>'
                                . '<div>• GAUTH = مفتاح المصادقة (Base32)، وليس كوداً من 6 أرقام.</div>'
                                . '<div>• الصف الأول للعناوين، وكل صف بعده = حساب واحد.</div>'
                                . '<div>• حمّل نموذج المنصة من زر «تحميل النموذج» أعلاه وعبّئه.</div>'
                                . '</div>'
                            )),

                        Forms\Components\Select::make('platform')
                            ->label('منصة الحسابات في هذا الملف')
                            ->options(Account::PLATFORMS)
                            ->default(Account::PLATFORM_PSN)
                            ->required()
                            ->native(false),

                        Forms\Components\FileUpload::make('file')
                            ->label('ملف الحسابات')
                            ->required()
                            ->disk('local')
                            ->directory('club-creation-imports')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                                'text/plain',
                            ])
                            ->maxSize(5120),
                    ])
                    ->action(function (array $data): void {
                        $tenantId = Filament::getTenant()?->id;

                        if ($tenantId === null) {
                            Notification::make()->title('لا يوجد مستأجر نشط')->danger()->send();

                            return;
                        }

                        $relativePath = is_array($data['file'] ?? null) ? reset($data['file']) : $data['file'];

                        if (! $relativePath || ! Storage::disk('local')->exists($relativePath)) {
                            Notification::make()->title('لم يُقرأ الملف')->danger()->send();

                            return;
                        }

                        $fullPath = Storage::disk('local')->path($relativePath);
                        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

                        try {
                            $rows = $extension === 'csv'
                                ? self::readCsv($fullPath)
                                : self::readXlsx($fullPath);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('تعذّر قراءة الملف')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        } finally {
                            Storage::disk('local')->delete($relativePath);
                        }

                        $platform = array_key_exists($data['platform'] ?? '', Account::PLATFORMS)
                            ? $data['platform']
                            : Account::PLATFORM_PSN;

                        $stats = self::importRows($rows, $tenantId, $platform);

                        Notification::make()
                            ->title('تم الرفع')
                            ->body("أُضيف: {$stats['added']} — متكرر: {$stats['duplicates']} — تخطى: {$stats['skipped']}")
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->visible(fn (Account $r): bool => $r->status === Account::STATUS_AVAILABLE),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading('لا يوجد حسابات بعد')
            ->emptyStateDescription('ابدأ برفع دفعة من ملف Excel أو CSV (PlayStation أو Xbox). حمّل النموذج لتعرف ترتيب الأعمدة.')
            ->emptyStateActions([
                self::templateActions('emptyDownloadTemplate'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Row = [EMAIL, PW, EA PW, GAUTH, EA GAUTH]. The console GAUTH is
     * mandatory (the delivery link can't produce a code without it);
     * the EA pair is stored when present. Seeds must be valid Base32.
     *
     * @param  iterable<array{0:string,1:string,2:string,3:string,4:string}> $rows
     * @return array{added:int,duplicates:int,skipped:int}
     */
    protected static function importRows(iterable $rows, int $tenantId, string $platform): array
    {
        $added = 0;
        $duplicates = 0;
        $skipped = 0;
        $seenInBatch = [];

        DB::transaction(function () use ($rows, $tenantId, $platform, &$added, &$duplicates, &$skipped, &$seenInBatch): void {
            foreach ($rows as $row) {
                $email = trim((string) ($row[0] ?? ''));
                $password = trim((string) ($row[1] ?? ''));
                $eaPassword = trim((string) ($row[2] ?? ''));
                $seed = self::normalizeSeed($row[3] ?? null);
                $eaSeed = self::normalizeSeed($row[4] ?? null);

                if ($email === '' || $password === '' || $seed === null
                    || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                    || ! self::isValidBase32($seed)
                    || ($eaSeed !== null && ! self::isValidBase32($eaSeed))) {
                    $skipped++;
                    continue;
                }

                $exists = Account::where('tenant_id', $tenantId)
                    ->where('email', $email)
                    ->exists();

                if (isset($seenInBatch[$email]) || $exists) {
                    $duplicates++;
                    continue;
                }

                Account::create([
                    'tenant_id'    => $tenantId,
                    'platform'     => $platform,
                    'email'        => $email,
                    'password'     => $password,
                    'ea_password'  => $eaPassword !== '' ? $eaPassword : null,
                    'totp_seed'    => $seed,
                    'ea_totp_seed' => $eaSeed,
                    'status'       => Account::STATUS_AVAILABLE,
                ]);

                $seenInBatch[$email] = true;
                $added++;
            }
        });

        return compact('added', 'duplicates', 'skipped');
    }

    /**
     * @return list<array{0:string,1:string,2:string,3:string,4:string}>
     */
    protected static function readXlsx(string $path): array
    {
        $reader = new XlsxReader();
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $isFirst = true;
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(fn ($c) => (string) $c, $row->toArray());

                if ($isFirst) {
                    $isFirst = false;
                    $first = strtolower(trim($cells[0] ?? ''));
                    if (self::isHeaderCell($first)) {
                        continue;
                    }
                }

                if (($cells[0] ?? '') === '' && ($cells[1] ?? '') === '') {
                    continue;
                }

                $rows[] = [
                    $cells[0] ?? '',
                    $cells[1] ?? '',
                    $cells[2] ?? '',
                    $cells[3] ?? '',
                    $cells[4] ?? '',
                ];
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * @return list<array{0:string,1:string,2:string,3:string,4:string}>
     */
    protected static function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        $isFirst = true;
        while (($cells = fgetcsv($handle)) !== false) {
            if ($isFirst) {
                $isFirst = false;
                $first = strtolower(trim($cells[0] ?? ''));
                if (self::isHeaderCell($first)) {
                    continue;
                }
            }

            if (($cells[0] ?? '') === '' && ($cells[1] ?? '') === '') {
                continue;
            }

            $rows[] = [
                $cells[0] ?? '',
                $cells[1] ?? '',
                $cells[2] ?? '',
                $cells[3] ?? '',
                $cells[4] ?? '',
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Downloadable template, one per platform so each file is filled
     * and uploaded for a single platform. Sheet 1 is headers only — no
     * sample rows that could get imported by mistake (the importer
     * reads the first sheet alone); the instructions and an example
     * live on sheet 2.
     */
    protected static function streamTemplate(string $platform): StreamedResponse
    {
        $label = Account::PLATFORMS[$platform] ?? 'PlayStation';

        return new StreamedResponse(function () use ($label): void {
            $options = new XlsxOptions();
            $options->setColumnWidth(34, 1);
            $options->setColumnWidth(22, 2, 3);
            $options->setColumnWidth(40, 4, 5);

            $writer = new XlsxWriter($options);
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName('Accounts');

            $headerStyle = (new Style())
                ->setFontBold()
                ->setBackgroundColor(Color::rgb(79, 70, 229))
                ->setFontColor(Color::WHITE);

            $writer->addRow(Row::fromValues(
                ['EMAIL', 'PW', 'EA PW', 'GAUTH', 'EA GAUTH'],
                $headerStyle
            ));

            $writer->addNewSheetAndMakeItCurrent()->setName('تعليمات');
            $bold = (new Style())->setFontBold();

            foreach ([
                ['نموذج حسابات '.$label, '', '', '', ''],
                ['', '', '', '', ''],
                ['• عبّئ الحسابات في الورقة الأولى (Accounts) فقط — كل صف = حساب واحد، ولا تغيّر صف العناوين.', '', '', '', ''],
                ['• عند الرفع اختر المنصة: '.$label.'. لا تخلط حسابات منصتين في ملف واحد.', '', '', '', ''],
                ['• EMAIL و PW و GAUTH إلزامية. EA PW و EA GAUTH اختيارية.', '', '', '', ''],
                ['• GAUTH = المفتاح السري للمصادقة (حروف A-Z وأرقام 2-7)، وليس الكود المكوّن من 6 أرقام.', '', '', '', ''],
                ['• المسافات والأحرف الصغيرة في المفتاح مقبولة وتُنظَّف تلقائياً.', '', '', '', ''],
                ['• إذا كان الرمز أرقاماً تبدأ بصفر، اجعل خلايا العمود بتنسيق "نص" قبل اللصق.', '', '', '', ''],
                ['', '', '', '', ''],
            ] as $i => $line) {
                $writer->addRow(Row::fromValues($line, $i === 0 ? $bold : null));
            }

            $writer->addRow(Row::fromValues(['مثال (للتوضيح فقط — لا تنسخه):', '', '', '', ''], $bold));
            $writer->addRow(Row::fromValues(['EMAIL', 'PW', 'EA PW', 'GAUTH', 'EA GAUTH'], $headerStyle));
            $writer->addRow(Row::fromValues([
                'example@mail.com', 'Pass!Example123', 'EaPass!123', 'JBSWY3DPEHPK3PXP', 'KRSXG5CTMVRXEZLU',
            ]));

            $writer->close();
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="club-accounts-'.$platform.'-template.xlsx"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    /**
     * "تحميل النموذج" with one entry per platform.
     */
    protected static function templateActions(string $prefix): Tables\Actions\ActionGroup
    {
        return Tables\Actions\ActionGroup::make(
            collect(Account::PLATFORMS)
                ->map(fn (string $label, string $platform) => Tables\Actions\Action::make($prefix.ucfirst($platform))
                    ->label('نموذج '.$label)
                    ->action(fn () => self::streamTemplate($platform)))
                ->values()
                ->all()
        )
            ->label('تحميل النموذج')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->button();
    }

    protected static function isHeaderCell(string $first): bool
    {
        return in_array($first, ['email', 'e-mail', 'البريد', 'psn email', 'xbox email'], true);
    }

    /**
     * Authenticator exports often come spaced ("JBSW Y3DP ...") or in
     * lowercase; store the canonical uppercase, no-space form.
     */
    protected static function normalizeSeed(?string $seed): ?string
    {
        $seed = strtoupper((string) preg_replace('/[\s-]+/', '', (string) $seed));

        return $seed !== '' ? $seed : null;
    }

    protected static function isValidBase32(string $seed): bool
    {
        if (! preg_match('/^[A-Z2-7]+=*$/', $seed)) {
            return false;
        }

        try {
            Base32::decodeUpper($seed);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected static function base32Rule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail): void {
            $seed = self::normalizeSeed($value);
            if ($seed !== null && ! self::isValidBase32($seed)) {
                $fail('المفتاح ليس Base32 صالحاً.');
            }
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
        ];
    }
}
