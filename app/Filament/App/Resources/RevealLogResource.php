<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\RevealLogResource\Pages;
use App\Models\RevealLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only view of the reveal_logs audit table. Rows are append-only in
 * the database; the UI enforces that with no edit/delete actions.
 */
class RevealLogResource extends Resource
{
    protected static ?string $model = RevealLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'سجل التدقيق';

    protected static ?string $modelLabel = 'سجل';

    protected static ?string $pluralModelLabel = 'سجل التدقيق';

    protected static ?int $navigationSort = 10;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

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
     * Scope by tier:
     *  - Owner: entire tenant.
     *  - Manager: logs from employees in the offices they manage.
     *  - Supervisor: logs from employees in their single office.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $u = auth()->user();

        if ($u !== null && ! $u->isTenantOwner()) {
            $query->whereIn('user_id', $u->visibleEmployeeIds());
        }

        return $query->with(['user', 'account']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('الموظف')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('account_id')
                    ->label('الحساب')
                    ->formatStateUsing(fn ($state): string => '#'.$state)
                    ->searchable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('الإجراء')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'reveal_credentials' => 'warning',
                        'generate_totp'      => 'primary',
                        'complete'           => 'success',
                        'fail'               => 'danger',
                        'submit_proof'       => 'primary',
                        'approve'            => 'success',
                        'reject'             => 'danger',
                        default              => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'reveal_credentials' => 'كشف بيانات',
                        'generate_totp'      => 'توليد 2FA',
                        'complete'           => 'اكتمل',
                        'fail'               => 'فشل',
                        'submit_proof'       => 'إرسال إثبات',
                        'approve'            => 'اعتماد الإثبات',
                        'reject'             => 'رفض الإثبات',
                        default              => $state,
                    }),

                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user_agent')
                    ->label('المتصفح')
                    ->limit(30)
                    ->tooltip(fn (?string $state) => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('الإجراء')
                    ->options([
                        'reveal_credentials' => 'كشف بيانات',
                        'generate_totp'      => 'توليد 2FA',
                        'complete'           => 'اكتمل',
                        'fail'               => 'فشل',
                        'submit_proof'       => 'إرسال إثبات',
                        'approve'            => 'اعتماد الإثبات',
                        'reject'             => 'رفض الإثبات',
                    ]),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('الموظف')
                    ->relationship('user', 'name'),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('من'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['to'] ?? null,   fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRevealLogs::route('/'),
        ];
    }
}
