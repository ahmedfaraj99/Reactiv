<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClubCreation;

use App\Filament\Resources\ClubCreation\BatchResource\Pages;
use App\Models\ClubCreation\Account;
use App\Models\ClubCreation\Batch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'إنشاء حسابات EA';

    protected static ?string $navigationLabel = 'سجل الإنشاء';

    protected static ?string $modelLabel = 'دفعة';

    protected static ?string $pluralModelLabel = 'الدفعات';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        // Edit form (rarely used — batches are usually created via the
        // dedicated header action, which also picks accounts).
        return $form->schema([
            Forms\Components\TextInput::make('recipient')
                ->label('الزبون (اسم/رقم/رابط)')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('price_per_account')
                ->label('السعر لكل حساب')
                ->numeric()
                ->required()
                ->minValue(0),

            Forms\Components\Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('recipient')
                    ->label('الزبون')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('account_count')
                    ->label('العدد')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('done_progress')
                    ->label('التقدم')
                    ->state(function (Batch $r): string {
                        return $r->doneCount() . ' / ' . $r->account_count;
                    })
                    ->badge()
                    ->color(fn (Batch $r): string => $r->isFullyDone() ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('price_per_account')
                    ->label('السعر / حساب')
                    ->numeric(decimalPlaces: 2),

                Tables\Columns\TextColumn::make('total')
                    ->label('الإجمالي')
                    ->state(fn (Batch $r): string => number_format((float) $r->price_per_account * $r->account_count, 2)),

                Tables\Columns\IconColumn::make('opened_at')
                    ->label('فُتح؟')
                    ->boolean()
                    ->getStateUsing(fn (Batch $r): bool => $r->opened_at !== null),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('status')
                    ->form([
                        Forms\Components\Select::make('state')
                            ->label('الحالة')
                            ->options([
                                'pending'  => 'قيد التنفيذ',
                                'done'     => 'مكتمل',
                                'unopened' => 'لم يُفتح بعد',
                            ]),
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['state'] ?? null) {
                            'unopened' => $query->whereNull('opened_at'),
                            default    => $query,
                        };
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('createBatch')
                    ->label('إنشاء رابط تسليم')
                    ->icon('heroicon-o-link')
                    ->color('primary')
                    ->modalWidth('md')
                    ->form([
                        Forms\Components\TextInput::make('recipient')
                            ->label('اسم / رقم / رابط الزبون')
                            ->required()
                            ->maxLength(255)
                            ->helperText('نص حر — لن يُحفظ كسجل زبون'),

                        Forms\Components\TextInput::make('count')
                            ->label('عدد الحسابات')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->default(1)
                            ->helperText(fn (): string => 'المتاح حالياً: ' . Account::where('status', Account::STATUS_AVAILABLE)->count()),

                        Forms\Components\TextInput::make('price_per_account')
                            ->label('السعر لكل حساب')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->default(0),

                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات (اختياري)')
                            ->rows(2),
                    ])
                    ->action(function (array $data): void {
                        $count = (int) $data['count'];

                        $batch = DB::transaction(function () use ($data, $count): ?Batch {
                            $accounts = Account::where('status', Account::STATUS_AVAILABLE)
                                ->orderBy('id')
                                ->limit($count)
                                ->lockForUpdate()
                                ->get();

                            if ($accounts->count() < $count) {
                                return null;
                            }

                            $batch = Batch::create([
                                'recipient'         => $data['recipient'],
                                'account_count'     => $count,
                                'price_per_account' => $data['price_per_account'],
                                'notes'             => $data['notes'] ?? null,
                            ]);

                            Account::whereIn('id', $accounts->pluck('id'))->update([
                                'status'   => Account::STATUS_ASSIGNED,
                                'batch_id' => $batch->id,
                            ]);

                            return $batch;
                        });

                        if ($batch === null) {
                            Notification::make()
                                ->title('لا يوجد حسابات كافية')
                                ->body('عدد الحسابات المتاحة أقل من المطلوب. ارفع حسابات جديدة أولاً.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('تم إنشاء الرابط')
                            ->body($batch->publicUrl())
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('copyLink')
                    ->label('نسخ الرابط')
                    ->icon('heroicon-o-clipboard-document')
                    ->color('info')
                    ->action(function (Batch $record): void {
                        // The copy itself is handled client-side via
                        // ->extraAttributes below; this closure just
                        // surfaces the URL as a notification too so
                        // it's always visible even if the clipboard
                        // API is blocked in the browser context.
                        Notification::make()
                            ->title('الرابط')
                            ->body($record->publicUrl())
                            ->success()
                            ->send();
                    })
                    ->extraAttributes(fn (Batch $record): array => [
                        'x-on:click' => 'navigator.clipboard.writeText(' . json_encode($record->publicUrl()) . ')',
                    ]),

                Tables\Actions\Action::make('viewAccounts')
                    ->label('عرض الحسابات')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Batch $r): string => 'حسابات دفعة #' . $r->id . ' — ' . $r->recipient)
                    ->modalContent(fn (Batch $r) => view('filament.club-creation.batch-accounts', ['batch' => $r]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق'),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->requiresConfirmation()
                    ->modalDescription('الحسابات المُسنَدة لهذه الدفعة ستعود إلى "متاح".')
                    ->before(function (Batch $record): void {
                        // Return still-untouched accounts to the pool.
                        // Completed ones stay attached for history.
                        Account::where('batch_id', $record->id)
                            ->where('status', Account::STATUS_ASSIGNED)
                            ->update([
                                'status'   => Account::STATUS_AVAILABLE,
                                'batch_id' => null,
                            ]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('exportDone')
                    ->label('تصدير المكتمل (Excel)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(fn (Collection $records) => self::exportDoneAccounts($records)),

                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Stream an XLSX of every done account across the selected batches
     * and mark them as exported so the same rows don't get delivered
     * twice.
     */
    protected static function exportDoneAccounts(Collection $records): StreamedResponse
    {
        $batchIds = $records->pluck('id')->all();

        $filename = 'club-accounts-' . now()->format('Ymd-His') . '.xlsx';

        return new StreamedResponse(function () use ($batchIds): void {
            $writer = new XlsxWriter();
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues([
                'Email', 'Password', 'Recipient', 'Batch #', 'Done At',
            ]));

            $ids = [];

            Account::query()
                ->with('batch')
                ->whereIn('batch_id', $batchIds)
                ->where('status', Account::STATUS_DONE)
                ->orderBy('id')
                ->chunk(500, function ($chunk) use ($writer, &$ids): void {
                    foreach ($chunk as $account) {
                        $writer->addRow(Row::fromValues([
                            $account->email,
                            $account->password,
                            $account->batch?->recipient ?? '',
                            $account->batch_id,
                            optional($account->done_at)->format('Y-m-d H:i'),
                        ]));
                        $ids[] = $account->id;
                    }
                });

            $writer->close();

            if ($ids !== []) {
                Account::whereIn('id', $ids)->update([
                    'status'      => Account::STATUS_EXPORTED,
                    'exported_at' => now(),
                ]);
            }
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBatches::route('/'),
            'edit'  => Pages\EditBatch::route('/{record}/edit'),
        ];
    }
}
