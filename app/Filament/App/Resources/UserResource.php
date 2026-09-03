<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Enums\UserRole;
use App\Filament\App\Resources\UserResource\Pages;
use App\Models\Office;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 2;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    /**
     * Each tier only ever sees/manages the ONE tier directly below it —
     * owner→managers, manager→supervisors, supervisor→employees. So the
     * label should say exactly who's in the list, not a generic "Users".
     */
    public static function getNavigationLabel(): string
    {
        return match (self::managedRole()) {
            UserRole::Manager    => 'المديرون',
            UserRole::Supervisor => 'المشرفون',
            UserRole::Employee   => 'الموظفون',
            default              => 'المستخدمون',
        };
    }

    public static function getModelLabel(): string
    {
        return self::managedRole()?->label() ?? 'مستخدم';
    }

    public static function getPluralModelLabel(): string
    {
        return self::getNavigationLabel();
    }

    public static function canAccess(): bool
    {
        return self::managedRole() !== null;
    }

    public static function canCreate(): bool
    {
        return self::managedRole() !== null;
    }

    public static function canEdit($record): bool
    {
        return self::managedRole() !== null;
    }

    public static function canDelete($record): bool
    {
        return self::managedRole() !== null;
    }

    /**
     * The one role tier the current user directly manages — strict
     * hierarchy, no skipping levels: owner→Manager, manager→Supervisor,
     * supervisor→Employee. Null means this resource isn't for them.
     */
    public static function managedRole(): ?UserRole
    {
        $u = auth()->user();

        return match (true) {
            $u === null => null,
            $u->isTenantOwner() => UserRole::Manager,
            $u->isManager()     => UserRole::Supervisor,
            $u->isSupervisor()  => UserRole::Employee,
            default             => null,
        };
    }

    /**
     * Scoped to exactly the one tier this user manages — an owner sees
     * only managers, a manager sees only the supervisors in the offices
     * they run, a supervisor sees only the employees in their office.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $u = auth()->user();
        $role = self::managedRole();

        if ($u === null || $role === null) {
            return $query->whereRaw('1=0');
        }

        $query->whereHas('roles', fn ($q) => $q->where('name', $role->value));

        if ($u->isManager()) {
            $officeIds = $u->managedOffices()->pluck('id');
            $query->whereIn('office_id', $officeIds);
        } elseif ($u->isSupervisor()) {
            $query->where('office_id', $u->office_id);
        }

        // Correlated subquery for the "آخر نشاط" column — one scan of
        // reveal_logs per row rendered instead of a JOIN that would
        // duplicate rows or need a GROUP BY. reveal_logs is indexed on
        // user_id, so the MAX() collapses to an index probe.
        $query->addSelect([
            'last_activity_at' => \App\Models\RevealLog::query()
                ->selectRaw('MAX(created_at)')
                ->whereColumn('reveal_logs.user_id', 'users.id'),
        ]);

        return $query;
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
                        // Soft-deleted rows keep the email column populated,
                        // so the default unique rule blocks re-adding a
                        // supervisor a manager just deleted. Skip
                        // soft-deleted rows here — matches the partial
                        // unique index the migration installs on the DB.
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at'),
                        ),

                    Forms\Components\TextInput::make('phone')
                        ->label('رقم الهاتف')
                        ->tel()
                        ->maxLength(50),

                    Forms\Components\TextInput::make('password')
                        ->label('كلمة المرور')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        // On CREATE: no password field — the user sets it
                        // themselves via the emailed activation link. On
                        // EDIT: still available so managers can force-reset
                        // a password for a locked-out user.
                        ->visible(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText('اتركها فارغة لعدم التغيير'),
                ]),

            Forms\Components\Section::make('الدور والمكتب')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('role_display')
                        ->label('الدور')
                        ->content(fn (): string => self::managedRole()?->label() ?? '—'),

                    Forms\Components\Select::make('office_id')
                        ->label('المكتب')
                        ->relationship('office', 'name', function (Builder $query): Builder {
                            $tenant = filament()->getTenant();
                            if ($tenant === null) {
                                return $query->whereRaw('1=0');
                            }

                            $query->where('tenant_id', $tenant->id);

                            // Scope the picker to the caller's own turf so
                            // a manager can't attach a new supervisor to
                            // another manager's office by picking it here.
                            $u = auth()->user();
                            if ($u?->isManager()) {
                                $query->whereIn('id', $u->managedOffices()->pluck('id'));
                            } elseif ($u?->isSupervisor() && $u->office_id !== null) {
                                $query->where('id', $u->office_id);
                            }

                            return $query;
                        })
                        ->searchable()
                        ->preload()
                        ->required(fn (): bool => self::managedRole() !== UserRole::Manager)
                        // Filtering the picker options is not enough — a
                        // hand-crafted submit could smuggle an office_id
                        // from another tenant. Bind the exists rule to the
                        // current tenant so the DB rejects such payloads.
                        ->rule(fn () => Rule::exists('offices', 'id')
                            ->where(fn ($q) => $q->where('tenant_id', filament()->getTenant()?->id ?? 0)))
                        ->helperText('مطلوب للمشرفين والموظفين. المدير لا يرتبط بمكتب واحد بعينه — يُعيَّن على المكاتب من شاشة المكاتب.'),

                    Forms\Components\Toggle::make('active')
                        ->label('نشط')
                        ->default(true)
                        ->inline(false),

                    // Per-employee trust flag: when ON, every activation
                    // by this employee requires a proof photo; when OFF,
                    // proof is optional. Only meaningful for employees —
                    // supervisors/managers never activate accounts, so
                    // hide the toggle from their edit forms.
                    Forms\Components\Toggle::make('requires_proof')
                        ->label('يتطلب صورة إثبات عند التفعيل')
                        ->helperText('افتراضياً مُفعَّل. أطفئه للموظفين الموثوقين لجعل رفع الإثبات اختياري في تفعيلاتهم.')
                        ->default(true)
                        ->inline(false)
                        ->visible(fn (): bool => self::managedRole() === UserRole::Employee),
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

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('الدور')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => UserRole::from($state)->label())
                    ->color(fn (string $state): string => match ($state) {
                        UserRole::Manager->value    => 'primary',
                        UserRole::Supervisor->value => 'warning',
                        UserRole::Employee->value   => 'info',
                        default => 'gray',
                    }),

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
                        ? 'المستخدم فعّل حسابه'
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
                    ->tooltip(fn (User $record): string => $record->requires_proof
                        ? 'موظف يتطلب رفع صورة إثبات لكل تفعيل'
                        : 'موظف موثوق — رفع الإثبات اختياري')
                    ->visible(fn (): bool => self::managedRole() === UserRole::Employee)
                    ->toggleable(),

                Tables\Columns\IconColumn::make('google2fa_enabled')
                    ->label('2FA')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->trueColor('success')
                    ->falseIcon('heroicon-o-shield-exclamation')
                    ->falseColor('warning'),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('آخر دخول')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم يدخل بعد')
                    ->sortable()
                    ->toggleable(),

                // Last time this user did anything sensitive (revealed
                // credentials, generated a TOTP, submitted a proof).
                // Beats last_login_at for "is this person working right
                // now?" — someone logged in yesterday morning but idle
                // since noon reads as "at desk" here even though
                // they're not doing anything.
                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('آخر نشاط')
                    ->dateTime('Y-m-d H:i')
                    ->since()
                    ->placeholder('لا نشاط')
                    ->sortable()
                    ->badge()
                    ->color(function (?string $state): string {
                        if ($state === null) {
                            return 'gray';
                        }
                        $minutes = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($state));
                        return match (true) {
                            $minutes <= 5   => 'success',
                            $minutes <= 30  => 'warning',
                            $minutes <= 240 => 'gray',
                            default         => 'gray',
                        };
                    })
                    ->icon(fn (?string $state): ?string => $state !== null
                        && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($state)) <= 5
                        ? 'heroicon-m-signal' : null),

                Tables\Columns\IconColumn::make('device_fingerprint')
                    ->label('جهاز مربوط')
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => filled($record->device_fingerprint))
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
                    ->modalDescription('سيتم إرسال رابط تفعيل جديد صالح 72 ساعة إلى بريد المستخدم.')
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
                Tables\Actions\Action::make('reset_2fa')
                    ->label('إعادة تعيين 2FA')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('سيُطلب من المستخدم إعداد 2FA من جديد عند تسجيل الدخول القادم.')
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'google2fa_secret'  => null,
                            'google2fa_enabled' => false,
                        ])->save();
                    })
                    ->visible(fn (User $record): bool => $record->google2fa_enabled),
                Tables\Actions\Action::make('reset_device')
                    ->label('إعادة تعيين الجهاز')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('عند أول دخول تالٍ من أي جهاز، سيُعتمد ذلك الجهاز تلقائياً كجهاز موثوق جديد — بدون تنبيه "جهاز جديد". استخدمها فقط بعد التأكد أن الموظف غيّر جهازه فعلاً.')
                    ->action(function (User $record): void {
                        $record->forceFill(['device_fingerprint' => null])->save();
                    })
                    ->visible(fn (User $record): bool => filled($record->device_fingerprint)),
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
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
