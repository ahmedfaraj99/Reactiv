<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\OfficeResource\Pages;
use App\Models\Office;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OfficeResource extends Resource
{
    protected static ?string $model = Office::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'المكاتب';

    protected static ?string $modelLabel = 'مكتب';

    protected static ?string $pluralModelLabel = 'المكاتب';

    protected static ?int $navigationSort = 1;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    /**
     * Offices are the manager's own turf, not the owner's — the owner
     * manages managers (see UserResource), and each manager runs their
     * own office(s). Supervisors and employees have no access here.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    /**
     * A manager only ever sees the offices assigned to them.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $u = auth()->user();

        return $query->where('manager_id', $u?->id);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('اسم المكتب')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('city')
                ->label('المدينة')
                ->maxLength(255),

            Forms\Components\Toggle::make('active')
                ->label('نشط')
                ->default(true)
                ->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('city')
                    ->label('المدينة')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('المستخدمون')
                    ->counts('users')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\IconColumn::make('active')
                    ->label('نشط')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('أُنشئ')
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('الحالة'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOffices::route('/'),
            'create' => Pages\CreateOffice::route('/create'),
            'edit'   => Pages\EditOffice::route('/{record}/edit'),
        ];
    }
}
