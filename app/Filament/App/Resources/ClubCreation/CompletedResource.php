<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ClubCreation;

use App\Enums\UserRole;
use App\Filament\App\Resources\ClubCreation\CompletedResource\Pages;
use App\Models\ClubCreation\Account;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cross-batch log of every completed (done + already-exported) account
 * so the owner has a single place to see the full history and bulk-
 * export it — separate from the per-batch view under سجل الإنشاء.
 */
class CompletedResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $slug = 'club-creation/completed';

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationGroup = 'إنشاء حسابات EA';

    protected static ?string $navigationLabel = 'الحسابات المكتملة';

    protected static ?string $modelLabel = 'حساب مكتمل';

    protected static ?string $pluralModelLabel = 'الحسابات المكتملة';

    protected static ?int $navigationSort = 92;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::TenantOwner->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->id;

        return parent::getEloquentQuery()
            ->when($tenantId, fn (Builder $q) => $q->where('tenant_id', $tenantId))
            ->whereIn('status', [Account::STATUS_DONE, Account::STATUS_EXPORTED])
            ->with('batch');
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

                Tables\Columns\TextColumn::make('batch.recipient')
                    ->label('الزبون')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('batch_id')
                    ->label('الدفعة #')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => $state === Account::STATUS_EXPORTED ? 'info' : 'success')
                    ->formatStateUsing(fn (string $state): string => $state === Account::STATUS_EXPORTED ? 'مُصدَّر' : 'مكتمل'),

                Tables\Columns\TextColumn::make('done_at')
                    ->label('تاريخ الإنجاز')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('exported_at')
                    ->label('تاريخ التصدير')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        Account::STATUS_DONE     => 'مكتمل (لم يُصدَّر)',
                        Account::STATUS_EXPORTED => 'مُصدَّر',
                    ]),

                Tables\Filters\Filter::make('done_between')
                    ->label('نطاق تاريخ الإنجاز')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('من'),
                        Forms\Components\DatePicker::make('to')->label('إلى'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('done_at', '>=', $d))
                            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('done_at', '<=', $d));
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportAll')
                    ->label('تصدير كل غير المُصدَّر (Excel)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تصدير كل الحسابات المكتملة')
                    ->modalDescription('سيتم تصدير كل الحسابات المكتملة التي لم تُصدَّر بعد، ثم تُعلَّم كـ "مُصدَّر" لن تظهر في تصدير لاحق.')
                    ->modalSubmitActionLabel('تصدير')
                    ->action(function () {
                        $tenantId = Filament::getTenant()?->id;

                        if ($tenantId === null) {
                            Notification::make()->title('لا يوجد مستأجر نشط')->danger()->send();

                            return null;
                        }

                        $count = Account::where('tenant_id', $tenantId)
                            ->where('status', Account::STATUS_DONE)
                            ->count();

                        if ($count === 0) {
                            Notification::make()
                                ->title('لا يوجد جديد للتصدير')
                                ->body('كل الحسابات المكتملة سبق تصديرها.')
                                ->warning()
                                ->send();

                            return null;
                        }

                        return self::streamExport($tenantId, onlyUnexported: true);
                    }),

                Tables\Actions\Action::make('exportFiltered')
                    ->label('تصدير الظاهر حالياً (Excel)')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(function ($livewire) {
                        $tenantId = Filament::getTenant()?->id;

                        if ($tenantId === null) {
                            Notification::make()->title('لا يوجد مستأجر نشط')->danger()->send();

                            return null;
                        }

                        // Snapshot the ids currently visible via the
                        // page's filtered query so the export matches
                        // what the operator sees, then mark them as
                        // exported so future "unexported-only" runs
                        // won't include them again.
                        $ids = $livewire->getFilteredTableQuery()->pluck('id')->all();

                        if ($ids === []) {
                            Notification::make()->title('لا يوجد شيء للتصدير')->warning()->send();

                            return null;
                        }

                        return self::streamExport($tenantId, ids: $ids);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('exportSelected')
                    ->label('تصدير المحدد (Excel)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(function ($records) {
                        $tenantId = Filament::getTenant()?->id;
                        $ids = $records->pluck('id')->all();

                        return self::streamExport($tenantId, ids: $ids);
                    }),
            ])
            ->defaultSort('done_at', 'desc');
    }

    /**
     * Stream an XLSX of completed accounts. If `ids` is given, exactly
     * those rows are exported (must belong to the tenant). Otherwise
     * every completed row in the tenant is exported; when
     * `onlyUnexported` is true it skips rows already marked as
     * exported. Exported rows are flipped to STATUS_EXPORTED so the
     * next un-exported-only pass doesn't repeat them.
     */
    protected static function streamExport(int $tenantId, ?array $ids = null, bool $onlyUnexported = false): StreamedResponse
    {
        $filename = 'club-accounts-completed-' . now()->format('Ymd-His') . '.xlsx';

        return new StreamedResponse(function () use ($tenantId, $ids, $onlyUnexported): void {
            $writer = new XlsxWriter();
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues([
                'Email', 'Password', 'Recipient', 'Batch #', 'Done At', 'Exported At',
            ]));

            $writtenIds = [];

            $query = Account::query()
                ->with('batch')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', [Account::STATUS_DONE, Account::STATUS_EXPORTED]);

            if ($ids !== null) {
                $query->whereIn('id', $ids);
            }

            if ($onlyUnexported) {
                $query->where('status', Account::STATUS_DONE);
            }

            $query->orderBy('id')->chunk(500, function ($chunk) use ($writer, &$writtenIds): void {
                foreach ($chunk as $account) {
                    $writer->addRow(Row::fromValues([
                        $account->email,
                        $account->password,
                        $account->batch?->recipient ?? '',
                        $account->batch_id,
                        optional($account->done_at)->format('Y-m-d H:i'),
                        optional($account->exported_at)->format('Y-m-d H:i'),
                    ]));
                    $writtenIds[] = $account->id;
                }
            });

            $writer->close();

            if ($writtenIds !== []) {
                Account::whereIn('id', $writtenIds)
                    ->where('status', Account::STATUS_DONE)
                    ->update([
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
            'index' => Pages\ListCompleted::route('/'),
        ];
    }
}
