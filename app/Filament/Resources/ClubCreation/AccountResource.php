<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClubCreation;

use App\Filament\Resources\ClubCreation\AccountResource\Pages;
use App\Models\ClubCreation\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'إنشاء حسابات EA';

    protected static ?string $navigationLabel = 'الحسابات';

    protected static ?string $modelLabel = 'حساب';

    protected static ?string $pluralModelLabel = 'الحسابات';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

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
                Tables\Actions\Action::make('bulkUpload')
                    ->label('رفع دفعة حسابات')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->form([
                        Forms\Components\Textarea::make('lines')
                            ->label('الحسابات')
                            ->helperText('سطر لكل حساب بصيغة email:password (أو مفصولة بمسافة/تاب)')
                            ->rows(12)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $stats = self::importLines((string) $data['lines']);

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
     * Parse a multi-line textarea into (email, password) pairs and
     * insert only the ones we haven't already stored. The intake is
     * deliberately forgiving about the separator: colon, tab, or any
     * run of whitespace, since operators paste from many sources.
     */
    protected static function importLines(string $raw): array
    {
        $added = 0;
        $duplicates = 0;
        $skipped = 0;

        $seenInBatch = [];

        DB::transaction(function () use ($raw, &$added, &$duplicates, &$skipped, &$seenInBatch): void {
            foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                // Split on the first colon, tab, or run of whitespace.
                $parts = preg_split('/\s*[:\t]\s*|\s+/', $line, 2);
                if (! is_array($parts) || count($parts) !== 2) {
                    $skipped++;
                    continue;
                }

                [$email, $password] = $parts;
                $email = trim($email);
                $password = trim($password);

                if ($email === '' || $password === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    continue;
                }

                if (isset($seenInBatch[$email]) || Account::where('email', $email)->exists()) {
                    $duplicates++;
                    continue;
                }

                Account::create([
                    'email'    => $email,
                    'password' => $password,
                    'status'   => Account::STATUS_AVAILABLE,
                ]);

                $seenInBatch[$email] = true;
                $added++;
            }
        });

        return compact('added', 'duplicates', 'skipped');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
        ];
    }
}
