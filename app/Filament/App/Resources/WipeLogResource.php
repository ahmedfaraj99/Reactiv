<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\WipeLogResource\Pages;
use App\Models\WipeLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only audit view of every End-of-Day credential wipe. Owner-only.
 * Never editable or deletable — the whole point of the log is that it
 * cannot be doctored after a suspicious wipe.
 */
class WipeLogResource extends Resource
{
    protected static ?string $model = WipeLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 91;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    protected static ?string $modelLabel = 'سجل مسح';

    protected static ?string $pluralModelLabel = 'سجل عمليات المسح';

    protected static ?string $navigationLabel = 'سجل المسح';

    public static function canAccess(): bool
    {
        return auth()->user()?->isTenantOwner() ?? false;
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('wiped_at')
                    ->label('التاريخ')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('العميل')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('wiper.name')
                    ->label('نُفِّذ بواسطة')
                    ->searchable(),

                Tables\Columns\TextColumn::make('accounts_wiped')
                    ->label('عدد الحسابات')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->toggleable(),
            ])
            ->defaultSort('wiped_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWipeLogs::route('/'),
        ];
    }
}
