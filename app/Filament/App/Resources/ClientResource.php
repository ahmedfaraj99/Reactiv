<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ClientResource\Pages;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * External clients whom the owner is providing account activation for.
 * Owner-only — managers/supervisors/employees never need to touch this;
 * the client is metadata attached at upload time and displayed on the
 * End-of-Day handoff page.
 */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 3;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    protected static ?string $modelLabel = 'عميل';

    protected static ?string $pluralModelLabel = 'العملاء';

    protected static ?string $navigationLabel = 'العملاء';

    public static function canAccess(): bool
    {
        return auth()->user()?->isTenantOwner() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('الاسم')
                ->required()
                ->maxLength(255)
                // Scoped to this tenant only, and ignoring the currently
                // edited row so an in-place rename doesn't collide with
                // itself. Backed by a DB unique index for the race case.
                ->rule(fn ($record) => Rule::unique('clients', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', filament()->getTenant()?->id ?? 0))
                    ->ignore($record?->id)
                    ->whereNull('deleted_at')),

            Forms\Components\TextInput::make('email')
                ->label('البريد الإلكتروني')
                ->email()
                ->maxLength(255)
                ->helperText('اختياري — يُستخدم لاحقاً لإرسال ملف نهاية اليوم تلقائياً.'),

            Forms\Components\TextInput::make('phone')
                ->label('الهاتف')
                ->maxLength(40),

            Forms\Components\Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(3)
                ->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('الهاتف')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('accounts_count')
                    ->label('إجمالي الحسابات')
                    ->counts('accounts')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('أُنشئ')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
        ];
    }
}
