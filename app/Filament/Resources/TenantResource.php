<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'العملاء';

    protected static ?string $modelLabel = 'عميل';

    protected static ?string $pluralModelLabel = 'العملاء';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('البيانات الأساسية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('اسم العميل / الشركة')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('slug')
                        ->label('المعرّف (slug)')
                        ->maxLength(255)
                        ->helperText('يُولَّد تلقائياً إن تُرك فارغاً')
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('subdomain')
                        ->label('النطاق الفرعي')
                        ->maxLength(255)
                        ->helperText('مثال: client1 → client1.fc27ac.com')
                        ->unique(ignoreRecord: true),
                ]),

            Forms\Components\Section::make('المالك الأول (Tenant Owner)')
                ->description('حساب سيدخل لوحة /app لأول مرة ويدير هذه الشركة')
                ->columns(2)
                ->visibleOn('create')
                ->schema([
                    Forms\Components\TextInput::make('owner_name')
                        ->label('اسم المالك')
                        ->required()
                        ->maxLength(255)
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('owner_email')
                        ->label('البريد الإلكتروني')
                        ->email()
                        ->required()
                        ->unique(table: 'users', column: 'email')
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('owner_phone')
                        ->label('رقم الهاتف')
                        ->tel()
                        ->maxLength(50)
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('owner_password')
                        ->label('كلمة مرور مؤقتة')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->required()
                        ->helperText('سيغيّرها المالك بعد أول دخول')
                        ->dehydrated(false),
                ]),

            Forms\Components\Section::make('الاشتراك والحدود')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'trial' => 'تجريبية',
                            'active' => 'نشطة',
                            'suspended' => 'موقوفة',
                        ])
                        ->default('trial')
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('plan')
                        ->label('الخطة')
                        ->options([
                            'starter' => 'Starter',
                            'pro' => 'Pro',
                            'enterprise' => 'Enterprise',
                        ])
                        ->default('starter')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('max_accounts')
                        ->label('حد أقصى للحسابات')
                        ->numeric()
                        ->required()
                        ->default(1000)
                        ->minValue(0),

                    Forms\Components\TextInput::make('max_employees')
                        ->label('حد أقصى للموظفين')
                        ->numeric()
                        ->required()
                        ->default(50)
                        ->minValue(0),

                    Forms\Components\DateTimePicker::make('trial_ends_at')
                        ->label('انتهاء الفترة التجريبية')
                        ->native(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم العميل')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('المعرّف')
                    ->searchable()
                    ->color('gray')
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('subdomain')
                    ->label('النطاق')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'trial' => 'warning',
                        'active' => 'success',
                        'suspended' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'trial' => 'تجريبية',
                        'active' => 'نشطة',
                        'suspended' => 'موقوفة',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('plan')
                    ->label('الخطة')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('المستخدمون')
                    ->counts('users')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('offices_count')
                    ->label('المكاتب')
                    ->counts('offices')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('max_accounts')
                    ->label('حد الحسابات')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'trial' => 'تجريبية',
                        'active' => 'نشطة',
                        'suspended' => 'موقوفة',
                    ]),
                Tables\Filters\SelectFilter::make('plan')
                    ->label('الخطة')
                    ->options([
                        'starter' => 'Starter',
                        'pro' => 'Pro',
                        'enterprise' => 'Enterprise',
                    ]),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\Action::make('suspend')
                    ->label(fn (Tenant $r) => $r->status === 'suspended' ? 'إعادة تفعيل' : 'إيقاف')
                    ->icon(fn (Tenant $r) => $r->status === 'suspended' ? 'heroicon-o-play' : 'heroicon-o-pause')
                    ->color(fn (Tenant $r) => $r->status === 'suspended' ? 'success' : 'warning')
                    ->requiresConfirmation()
                    ->action(function (Tenant $record): void {
                        $record->update([
                            'status' => $record->status === 'suspended' ? 'active' : 'suspended',
                        ]);
                    }),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                    Tables\Actions\ForceDeleteBulkAction::make()->label('حذف نهائي'),
                    Tables\Actions\RestoreBulkAction::make()->label('استعادة'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
