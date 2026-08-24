<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\AccountAdminLogResource\Pages;
use App\Models\AccountAdminLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only view of archive/permanent-delete actions on accounts,
 * restricted to the tenant owner and the manager (the manager is the
 * one who actually runs the failed-account export/delete flow, so
 * they need to see its trail too). Independent of the accounts table
 * itself, so entries survive even after the account they describe has
 * been permanently erased.
 */
class AccountAdminLogResource extends Resource
{
    protected static ?string $model = AccountAdminLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'سجل الأرشفة والحذف';

    protected static ?string $modelLabel = 'سجل';

    protected static ?string $pluralModelLabel = 'سجل الأرشفة والحذف';

    protected static ?int $navigationSort = 12;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwner() || $u->isManager());
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('الإجراء')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AccountAdminLog::ACTION_ARCHIVED             => 'gray',
                        AccountAdminLog::ACTION_UNARCHIVED           => 'success',
                        AccountAdminLog::ACTION_PERMANENTLY_DELETED  => 'danger',
                        default                                      => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        AccountAdminLog::ACTION_ARCHIVED             => 'أُرشف',
                        AccountAdminLog::ACTION_UNARCHIVED           => 'أُعيد من الأرشيف',
                        AccountAdminLog::ACTION_PERMANENTLY_DELETED  => 'مُسح نهائياً',
                        default                                      => $state,
                    }),

                Tables\Columns\TextColumn::make('account_id')
                    ->label('الحساب')
                    ->formatStateUsing(fn ($state): string => '#'.$state),

                Tables\Columns\TextColumn::make('account_email_masked')
                    ->label('البريد'),

                Tables\Columns\TextColumn::make('actor.name')
                    ->label('نفّذه')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('الإجراء')
                    ->options([
                        AccountAdminLog::ACTION_ARCHIVED             => 'أُرشف',
                        AccountAdminLog::ACTION_UNARCHIVED           => 'أُعيد من الأرشيف',
                        AccountAdminLog::ACTION_PERMANENTLY_DELETED  => 'مُسح نهائياً',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccountAdminLogs::route('/'),
        ];
    }
}
