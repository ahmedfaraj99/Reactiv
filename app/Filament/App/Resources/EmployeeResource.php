<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Enums\UserRole;
use App\Filament\App\Resources\EmployeeResource\Pages;
use App\Models\Office;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Manager-only view of the employees inside the offices they own.
 * Distinct from UserResource (which is scoped by managedRole to
 * exactly one tier below the caller) so managers get a first-class
 * sidebar entry for creating and managing employees directly, without
 * having to go through a supervisor.
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'الموظفون';

    protected static ?string $modelLabel = 'موظف';

    protected static ?string $pluralModelLabel = 'الموظفون';

    protected static ?int $navigationSort = 3;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function canAccess(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    public static function canEdit($record): bool
    {
        return self::canAccess();
    }

    public static function canDelete($record): bool
    {
        return self::canAccess();
    }

    /**
     * Only employees inside offices this manager owns. Filament's
     * base query already narrows by tenant via the panel tenancy, so
     * we only need the role + office scope here.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $u = auth()->user();

        if ($u === null || ! $u->isManager()) {
            return $query->whereRaw('1=0');
        }

        $officeIds = $u->managedOffices()->pluck('id');

        return $query
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value))
            ->whereIn('office_id', $officeIds);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('البيانات الأساسية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('الاسم الكامل')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->label('البريد الإلكتروني')
                        ->email()
                        ->required()
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at'),
                        ),

                    Forms\Components\TextInput::make('phone')
                        ->label('رقم الهاتف')
                        ->tel()
                        ->maxLength(50),
                ]),

            Forms\Components\Section::make('التبعية')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('office_id')
                        ->label('المكتب')
                        ->helperText('كل مشرفي هذا المكتب سيرون الموظف تلقائياً.')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(function (): array {
                            $u = auth()->user();
                            if ($u === null || ! $u->isManager()) {
                                return [];
                            }

                            return Office::query()
                                ->whereIn('id', $u->managedOffices()->pluck('id'))
                                ->orderBy('name')
                                ->get(['id', 'name'])
                                ->mapWithKeys(function (Office $office): array {
                                    $count = User::query()
                                        ->where('office_id', $office->id)
                                        ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Supervisor->value))
                                        ->count();

                                    $label = $office->name;
                                    $label .= $count > 0 ? " ({$count} مشرف)" : ' (بلا مشرف)';

                                    return [$office->id => $label];
                                })
                                ->all();
                        })
                        ->rule(function (): \Closure {
                            return function (string $attribute, $value, \Closure $fail): void {
                                $u = auth()->user();
                                $officeIds = $u?->managedOffices()->pluck('id')->all() ?? [];
                                if (! in_array((int) $value, $officeIds, true)) {
                                    $fail('المكتب المختار خارج نطاق صلاحيتك.');
                                }
                            };
                        }),

                    Forms\Components\Toggle::make('active')
                        ->label('نشط')
                        ->default(true)
                        ->inline(false),

                    Forms\Components\Toggle::make('requires_proof')
                        ->label('يتطلب صورة إثبات عند التفعيل')
                        ->helperText('افتراضياً مُفعَّل. أطفئه للموظفين الموثوقين لجعل رفع الإثبات اختياري.')
                        ->default(true)
                        ->inline(false),
                ]),
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

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('office.name')
                    ->label('المكتب')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('مُفعَّل')
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->email_verified_at !== null)
                    ->trueIcon('heroicon-o-check-badge')
                    ->trueColor('success')
                    ->falseIcon('heroicon-o-clock')
                    ->falseColor('warning')
                    ->tooltip(fn (User $record): string => $record->email_verified_at !== null
                        ? 'الموظف فعّل حسابه'
                        : 'دعوة معلّقة — لم يفتح رابط التفعيل بعد'),

                Tables\Columns\IconColumn::make('active')
                    ->label('نشط')
                    ->boolean(),

                Tables\Columns\IconColumn::make('requires_proof')
                    ->label('إثبات إلزامي')
                    ->boolean()
                    ->trueIcon('heroicon-o-camera')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-check-badge')
                    ->falseColor('success')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('آخر دخول')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم يدخل بعد')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('office_id')
                    ->label('المكتب')
                    ->relationship('office', 'name'),
                Tables\Filters\TernaryFilter::make('active')->label('الحالة'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\Action::make('resend_invitation')
                    ->label('إعادة إرسال دعوة')
                    ->icon('heroicon-o-envelope')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('سيتم إرسال رابط تفعيل جديد صالح 72 ساعة إلى بريد الموظف.')
                    ->visible(fn (User $record): bool => $record->email_verified_at === null)
                    ->action(function (User $record): void {
                        try {
                            $record->notify(new \App\Notifications\UserActivationInvitation);
                            \Filament\Notifications\Notification::make()
                                ->title('تم إرسال الدعوة')
                                ->body('تحقق من بريد '.$record->email)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('فشل إرسال الدعوة')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('copy_activation_link')
                    ->label('نسخ رابط التفعيل')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (User $record): bool => $record->email_verified_at === null)
                    ->modalHeading('رابط تفعيل الموظف')
                    ->modalDescription('انسخ الرابط وأرسله للموظف عبر واتساب/تلجرام. صالح 72 ساعة.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->form(fn (User $record): array => [
                        Forms\Components\TextInput::make('activation_url')
                            ->label('رابط التفعيل')
                            ->default(\Illuminate\Support\Facades\URL::temporarySignedRoute(
                                'activation.show',
                                now()->addHours(72),
                                ['user' => $record->getKey()],
                            ))
                            ->readOnly()
                            ->extraInputAttributes(['dir' => 'ltr', 'onclick' => 'this.select()'])
                            ->helperText('اضغط على الحقل لتحديد كامل الرابط ثم انسخه.'),
                    ]),
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
            'index'  => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit'   => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
