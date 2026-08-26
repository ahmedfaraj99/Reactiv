<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\OfficeBroadcastResource\Pages;
use App\Models\Office;
use App\Models\OfficeBroadcast;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OfficeBroadcastResource extends Resource
{
    protected static ?string $model = OfficeBroadcast::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'إعلانات المكتب';

    protected static ?string $modelLabel = 'إعلان';

    protected static ?string $pluralModelLabel = 'الإعلانات';

    protected static ?int $navigationSort = 12;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && $u->isTenantOwnerOrManager();
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    /**
     * Editing an active broadcast would let a manager silently rewrite
     * a message people have already read. Ending it early + posting a
     * new one is the clearer trail. So no edit — only view + end early
     * (soft delete).
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        $u = auth()->user();
        if ($u === null || $record === null) {
            return false;
        }
        if ($u->isTenantOwner()) {
            return true;
        }
        // A manager can only pull down their own posts (or ones targeting
        // an office they manage) — not another manager's broadcasts.
        return $record->sender_id === $u->id
            || ($record->office_id !== null && in_array($record->office_id, $u->managedOffices()->pluck('id')->all(), true));
    }

    /**
     * Owner sees every broadcast in the tenant; a manager sees the ones
     * they sent or that target an office they manage (plus tenant-wide
     * ones the owner posted, since those affect their people too).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['office', 'sender']);
        $u = auth()->user();

        if ($u?->isManager()) {
            $managedIds = $u->managedOffices()->pluck('id')->all();
            $query->where(function (Builder $q) use ($u, $managedIds): void {
                $q->whereNull('office_id')
                    ->orWhereIn('office_id', $managedIds)
                    ->orWhere('sender_id', $u->id);
            });
        }

        return $query;
    }

    public static function form(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        return $form->schema([
            Forms\Components\Select::make('office_id')
                ->label('المكتب المستهدف')
                ->helperText('اختر مكتباً محدداً، أو اترك فارغاً لبثّ الإعلان لكل المكاتب (المالك فقط).')
                ->options(fn (): array => self::officeOptions())
                ->searchable()
                ->preload()
                ->placeholder(fn (): string => auth()->user()?->isTenantOwner()
                    ? 'كل المكاتب في المؤسسة'
                    : '— اختر مكتباً —')
                ->required(fn (): bool => ! (auth()->user()?->isTenantOwner() ?? false)),

            Forms\Components\Textarea::make('message')
                ->label('نص الإعلان')
                ->required()
                ->rows(3)
                ->maxLength(500)
                ->helperText('يظهر كبانر أعلى كل صفحة لكل شخص في المكتب المستهدف.'),

            Forms\Components\Select::make('level')
                ->label('مستوى التنبيه')
                ->options([
                    OfficeBroadcast::LEVEL_INFO    => 'معلومة (أزرق)',
                    OfficeBroadcast::LEVEL_WARNING => 'تحذير (أصفر)',
                    OfficeBroadcast::LEVEL_DANGER  => 'حرج (أحمر)',
                ])
                ->default(OfficeBroadcast::LEVEL_INFO)
                ->required(),

            Forms\Components\DateTimePicker::make('expires_at')
                ->label('ينتهي في')
                ->helperText('اتركه فارغاً لإعلان دائم يجب إنهاؤه يدوياً. مقترح: ساعات قليلة لحالات الصيانة.')
                ->native(false)
                ->seconds(false)
                ->minDate(now())
                ->default(now()->addHours(2)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('نُشر')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('office.name')
                    ->label('المكتب')
                    ->placeholder('كل المكاتب')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'primary' : 'gray'),

                Tables\Columns\TextColumn::make('level')
                    ->label('المستوى')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        OfficeBroadcast::LEVEL_DANGER  => 'danger',
                        OfficeBroadcast::LEVEL_WARNING => 'warning',
                        default                        => 'info',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        OfficeBroadcast::LEVEL_DANGER  => 'حرج',
                        OfficeBroadcast::LEVEL_WARNING => 'تحذير',
                        default                        => 'معلومة',
                    }),

                Tables\Columns\TextColumn::make('message')
                    ->label('النص')
                    ->wrap()
                    ->limit(80),

                Tables\Columns\TextColumn::make('sender.name')
                    ->label('أرسله')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('ينتهي')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('— دائم —')
                    ->color(fn (?\Illuminate\Support\Carbon $state): string => $state === null
                        ? 'gray'
                        : ($state->isFuture() ? 'success' : 'danger'))
                    ->description(fn (?\Illuminate\Support\Carbon $state): ?string => $state?->diffForHumans()),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')
                    ->label('النشط فقط')
                    ->default()
                    ->query(fn (Builder $q): Builder => $q->active()),
            ])
            ->actions([
                Tables\Actions\Action::make('end')
                    ->label('إنهاء الآن')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (OfficeBroadcast $r): bool => $r->isActive() && self::canDelete($r))
                    ->requiresConfirmation()
                    ->action(function (OfficeBroadcast $record): void {
                        $record->delete();
                        Notification::make()->success()->title('تم إنهاء الإعلان')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOfficeBroadcasts::route('/'),
            'create' => Pages\CreateOfficeBroadcast::route('/create'),
        ];
    }

    /**
     * Owner: any office in the tenant. Manager: only the offices they run.
     *
     * @return array<int,string>
     */
    private static function officeOptions(): array
    {
        $u = auth()->user();
        $tenant = filament()->getTenant();
        if ($u === null || $tenant === null) {
            return [];
        }

        $query = Office::query()->where('tenant_id', $tenant->id)->where('active', true);
        if ($u->isManager()) {
            $query->where('manager_id', $u->id);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }
}
