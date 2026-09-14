<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ClubCreation;

use App\Enums\UserRole;
use App\Filament\App\Resources\ClubCreation\BatchResource\Pages;
use App\Models\ClubCreation\Account;
use App\Models\ClubCreation\Batch;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    protected static ?int $navigationSort = 91;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::TenantOwner->value) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->id;

        return parent::getEloquentQuery()->when(
            $tenantId,
            fn (Builder $q) => $q->where('tenant_id', $tenantId),
        );
    }

    public static function form(Form $form): Form
    {
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

            Forms\Components\Textarea::make('notes')->label('ملاحظات')->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),

                Tables\Columns\TextColumn::make('recipient')
                    ->label('الزبون')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('account_count')
                    ->label('العدد')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('overall_status')
                    ->label('الحالة')
                    ->state(function (Batch $r): string {
                        $done = $r->doneCount();
                        if ($r->opened_at === null) {
                            return 'لم يُفتح';
                        }
                        if ($done === 0) {
                            return 'قيد التنفيذ';
                        }
                        if ($done >= $r->account_count) {
                            return 'مكتمل';
                        }

                        return 'قيد التنفيذ';
                    })
                    ->badge()
                    ->color(function (Batch $r): string {
                        if ($r->opened_at === null) {
                            return 'gray';
                        }

                        return $r->isFullyDone() ? 'success' : 'warning';
                    }),

                Tables\Columns\TextColumn::make('done_progress')
                    ->label('التقدم')
                    ->state(fn (Batch $r): string => $r->doneCount() . ' / ' . $r->account_count)
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
                    ->getStateUsing(fn (Batch $r): bool => $r->opened_at !== null)
                    ->tooltip(fn (Batch $r): ?string => $r->opened_at
                        ? 'أول فتح: ' . $r->opened_at->format('Y-m-d H:i') . ($r->first_open_ip ? ' — IP: ' . $r->first_open_ip : '')
                        : null),

                Tables\Columns\TextColumn::make('first_open_ip')
                    ->label('IP أول فتح')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('first_open_ua')
                    ->label('المتصفح/الجهاز')
                    ->placeholder('—')
                    ->limit(50)
                    ->tooltip(fn (Batch $r): ?string => $r->first_open_ua)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('link_state')
                    ->label('الرابط')
                    ->state(function (Batch $r): string {
                        if ($r->isRevoked()) {
                            return 'مُبطَل';
                        }
                        if ($r->isExpired()) {
                            return 'منتهي';
                        }
                        if ($r->expires_at !== null) {
                            return 'ينتهي ' . $r->expires_at->diffForHumans();
                        }

                        return 'نشط';
                    })
                    ->badge()
                    ->color(function (Batch $r): string {
                        if ($r->isRevoked() || $r->isExpired()) {
                            return 'danger';
                        }
                        if ($r->expires_at !== null) {
                            return 'warning';
                        }

                        return 'success';
                    }),

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
                            ->helperText(function (): string {
                                $tenantId = Filament::getTenant()?->id;
                                $available = Account::where('tenant_id', $tenantId)
                                    ->where('status', Account::STATUS_AVAILABLE)
                                    ->count();

                                return 'المتاح حالياً: ' . $available;
                            }),

                        Forms\Components\TextInput::make('price_per_account')
                            ->label('السعر لكل حساب')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->default(0),

                        Forms\Components\Select::make('expires_in_days')
                            ->label('انتهاء الصلاحية')
                            ->options([
                                ''   => 'دائم (بلا انتهاء)',
                                '1'  => 'يوم واحد',
                                '3'  => '3 أيام',
                                '7'  => 'أسبوع',
                                '30' => 'شهر',
                            ])
                            ->default('')
                            ->native(false)
                            ->helperText('اختياري — بعد انتهاء المدة الرابط يعود 404.'),

                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات (اختياري)')
                            ->rows(2),
                    ])
                    ->action(function (array $data): void {
                        $tenantId = Filament::getTenant()?->id;

                        if ($tenantId === null) {
                            Notification::make()->title('لا يوجد مستأجر نشط')->danger()->send();

                            return;
                        }

                        $count = (int) $data['count'];

                        $batch = DB::transaction(function () use ($data, $count, $tenantId): ?Batch {
                            $accounts = Account::where('tenant_id', $tenantId)
                                ->where('status', Account::STATUS_AVAILABLE)
                                ->orderBy('id')
                                ->limit($count)
                                ->lockForUpdate()
                                ->get();

                            if ($accounts->count() < $count) {
                                return null;
                            }

                            $expiresAt = null;
                            $days = (int) ($data['expires_in_days'] ?? 0);
                            if ($days > 0) {
                                $expiresAt = now()->addDays($days);
                            }

                            $batch = Batch::create([
                                'tenant_id'         => $tenantId,
                                'recipient'         => $data['recipient'],
                                'account_count'     => $count,
                                'price_per_account' => $data['price_per_account'],
                                'notes'             => $data['notes'] ?? null,
                                'expires_at'        => $expiresAt,
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
                    ->action(function (Batch $record, \Livewire\Component $livewire): void {
                        $url = $record->publicUrl();
                        $urlJs = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                        // Livewire's js() runs after the server action
                        // returns. Try the modern clipboard API first,
                        // fall back to a legacy textarea + execCommand
                        // for in-app browsers (Messenger, older WebViews)
                        // where clipboard access is gated.
                        $livewire->js(<<<JS
                            (function(){
                                const text = {$urlJs};
                                if (navigator.clipboard && window.isSecureContext) {
                                    navigator.clipboard.writeText(text).catch(fallback);
                                } else { fallback(); }
                                function fallback(){
                                    const ta = document.createElement('textarea');
                                    ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
                                    document.body.appendChild(ta); ta.select();
                                    try { document.execCommand('copy'); } catch(e){}
                                    document.body.removeChild(ta);
                                }
                            })();
                        JS);

                        Notification::make()
                            ->title('نُسخ ✓')
                            ->success()
                            ->duration(2000)
                            ->send();
                    }),

                Tables\Actions\Action::make('viewAccounts')
                    ->label('عرض الحسابات')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Batch $r): string => 'حسابات دفعة #' . $r->id . ' — ' . $r->recipient)
                    ->modalContent(fn (Batch $r) => view('filament.club-creation.batch-accounts', ['batch' => $r]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق'),

                Tables\Actions\Action::make('revoke')
                    ->label(fn (Batch $r): string => $r->isRevoked() ? 'إلغاء الإبطال' : 'إبطال الرابط')
                    ->icon(fn (Batch $r): string => $r->isRevoked() ? 'heroicon-o-arrow-path' : 'heroicon-o-no-symbol')
                    ->color(fn (Batch $r): string => $r->isRevoked() ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Batch $r): string => $r->isRevoked()
                        ? 'سيعود الرابط للعمل، والزبون يستطيع فتحه مجدداً.'
                        : 'الرابط سيتوقّف عن العمل فوراً. الدفعة تبقى في السجل. يمكن التراجع لاحقاً.')
                    ->action(function (Batch $record): void {
                        $record->update([
                            'revoked_at' => $record->isRevoked() ? null : now(),
                        ]);

                        Notification::make()
                            ->title($record->isRevoked() ? 'تم إبطال الرابط' : 'تم إلغاء الإبطال')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('regenerateToken')
                    ->label('إعادة توليد الرابط')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('إعادة توليد رابط جديد')
                    ->modalDescription('الرابط القديم سيتوقّف نهائياً وسيُنشأ رابط جديد لنفس الحسابات. استعمل هذا لو أرسلت الرابط للشخص الخطأ.')
                    ->modalSubmitActionLabel('توليد رابط جديد')
                    ->action(function (Batch $record): void {
                        $record->update([
                            'token'      => Batch::freshToken(),
                            'opened_at'  => null,
                            'revoked_at' => null,
                        ]);

                        Notification::make()
                            ->title('تم توليد رابط جديد')
                            ->body($record->fresh()->publicUrl())
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->requiresConfirmation()
                    ->modalDescription('الحسابات المُسنَدة لهذه الدفعة ستعود إلى "متاح".')
                    ->before(function (Batch $record): void {
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

    protected static function exportDoneAccounts(Collection $records): StreamedResponse
    {
        $batchIds = $records->pluck('id')->all();

        $filename = 'club-accounts-' . now()->format('Ymd-His') . '.xlsx';

        return new StreamedResponse(function () use ($batchIds): void {
            $writer = new XlsxWriter();
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues([
                'Email', 'Password', 'EA Backup Code', 'PSN Backup Code',
                'Recipient', 'Batch #', 'Done At',
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
                            $account->ea_backup_code,
                            $account->psn_backup_code,
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
