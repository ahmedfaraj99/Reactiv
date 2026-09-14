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
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
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
            Forms\Components\TextInput::make('email')
                ->label('البريد الإلكتروني')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('password')
                ->label('كلمة المرور')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('ea_backup_code')
                ->label('EA Backup Code')
                ->required()
                ->maxLength(64),

            Forms\Components\TextInput::make('psn_backup_code')
                ->label('PSN Backup Code')
                ->required()
                ->maxLength(64),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('password')
                    ->label('كلمة المرور')
                    ->copyable()
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('ea_backup_code')
                    ->label('EA Backup')
                    ->copyable()
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('psn_backup_code')
                    ->label('PSN Backup')
                    ->copyable()
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

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
                Tables\Actions\Action::make('downloadTemplate')
                    ->label('تحميل النموذج')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(fn () => self::streamTemplate()),

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
                                . '<div>• الأعمدة بالترتيب: <b>email</b>, <b>password</b>, <b>ea_backup_code</b>, <b>psn_backup_code</b>.</div>'
                                . '<div>• الصف الأول للعناوين، وكل صف بعده = حساب واحد.</div>'
                                . '<div>• حمّل النموذج الفارغ أعلاه لتعرف الترتيب الصحيح.</div>'
                                . '</div>'
                            )),

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

                        $stats = self::importRows($rows, $tenantId);

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
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @param  iterable<array{0:string,1:string,2:string,3:string}> $rows
     * @return array{added:int,duplicates:int,skipped:int}
     */
    protected static function importRows(iterable $rows, int $tenantId): array
    {
        $added = 0;
        $duplicates = 0;
        $skipped = 0;
        $seenInBatch = [];

        DB::transaction(function () use ($rows, $tenantId, &$added, &$duplicates, &$skipped, &$seenInBatch): void {
            foreach ($rows as $row) {
                $email = trim((string) ($row[0] ?? ''));
                $password = trim((string) ($row[1] ?? ''));
                $eaCode = trim((string) ($row[2] ?? ''));
                $psnCode = trim((string) ($row[3] ?? ''));

                if ($email === '' || $password === '' || $eaCode === '' || $psnCode === ''
                    || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
                    'tenant_id'       => $tenantId,
                    'email'           => $email,
                    'password'        => $password,
                    'ea_backup_code'  => $eaCode,
                    'psn_backup_code' => $psnCode,
                    'status'          => Account::STATUS_AVAILABLE,
                ]);

                $seenInBatch[$email] = true;
                $added++;
            }
        });

        return compact('added', 'duplicates', 'skipped');
    }

    /**
     * @return list<array{0:string,1:string,2:string,3:string}>
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
                    if ($first === 'email' || $first === 'e-mail' || $first === 'البريد') {
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
                ];
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * @return list<array{0:string,1:string,2:string,3:string}>
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
                if ($first === 'email' || $first === 'e-mail' || $first === 'البريد') {
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
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Downloadable empty XLSX template so operators know the exact
     * column order (email, password) before filling their sheet.
     */
    protected static function streamTemplate(): StreamedResponse
    {
        return new StreamedResponse(function (): void {
            $writer = new XlsxWriter();
            $writer->openToFile('php://output');

            $headerStyle = (new Style())
                ->setFontBold()
                ->setBackgroundColor(Color::rgb(79, 70, 229))
                ->setFontColor(Color::WHITE);

            $writer->addRow(Row::fromValues(
                ['email', 'password', 'ea_backup_code', 'psn_backup_code'],
                $headerStyle
            ));

            $writer->addRow(Row::fromValues([
                'example1@ea.com', 'Pass!Example123', 'EA-8H4K-9P2M', 'PSN-Q7R2-N5X1',
            ]));
            $writer->addRow(Row::fromValues([
                'example2@ea.com', 'AnotherPass!456', 'EA-2F8V-6C3D', 'PSN-J4K9-B2M5',
            ]));

            $writer->close();
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="club-accounts-template.xlsx"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
        ];
    }
}
