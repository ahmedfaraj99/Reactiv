<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ClubCreation;

use App\Enums\UserRole;
use App\Filament\App\Resources\ClubCreation\TotpRequestResource\Pages;
use App\Models\ClubCreation\Account;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Exception requests from the public delivery link: a worker used up
 * the per-account TOTP allowance and asked for another code. Same
 * shape as the activation flow's "طلب كود إضافي", except the only
 * approver is the tenant owner — Club Creation workers are external
 * and have no supervisor in the system.
 */
class TotpRequestResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $slug = 'club-creation/totp-requests';

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'إنشاء حسابات EA';

    protected static ?string $navigationLabel = 'طلبات كود إضافي';

    protected static ?string $modelLabel = 'طلب كود إضافي';

    protected static ?string $pluralModelLabel = 'طلبات كود إضافي';

    protected static ?int $navigationSort = 93;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::TenantOwner->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->id;

        return parent::getEloquentQuery()
            ->when($tenantId, fn (Builder $q) => $q->where('tenant_id', $tenantId))
            ->where(fn (Builder $q) => $q
                ->whereNotNull('totp_requested_at')
                ->orWhereNotNull('ea_totp_requested_at'))
            ->with('batch');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('15s')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),

                Tables\Columns\TextColumn::make('email')
                    ->label('بريد الكونسول')
                    ->fontFamily('mono')
                    ->searchable(),

                Tables\Columns\TextColumn::make('batch.recipient')
                    ->label('المستلم')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('requested_kinds')
                    ->label('الطلب')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Account $r): array => collect(Account::TOTP_KINDS)
                        ->filter(fn (string $k): bool => $r->hasPendingTotpRequest($k))
                        ->map(fn (string $k): string => self::kindLabel($r, $k).' ('.$r->totpUsed($k).'/'.$r->totpAllowance($k).')')
                        ->values()
                        ->all()),

                Tables\Columns\TextColumn::make('requested_at')
                    ->label('وقت الطلب')
                    ->state(fn (Account $r) => collect([$r->totp_requested_at, $r->ea_totp_requested_at])->filter()->min())
                    ->since(),
            ])
            ->defaultSort(fn (Builder $q) => $q->orderByRaw('LEAST(totp_requested_at, ea_totp_requested_at)'))
            ->actions([
                self::approveAction(Account::KIND_CONSOLE),
                self::approveAction(Account::KIND_EA),

                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('يُرفض كل طلب معلّق على هذا الحساب.')
                    ->action(function (Account $record): void {
                        $record->update(['totp_requested_at' => null, 'ea_totp_requested_at' => null]);

                        Notification::make()->success()->title('رُفض الطلب')->send();
                    }),
            ])
            ->emptyStateIcon('heroicon-o-key')
            ->emptyStateHeading('لا توجد طلبات معلّقة');
    }

    protected static function kindLabel(Account $r, string $kind): string
    {
        return $kind === Account::KIND_EA ? 'كود EA' : 'كود '.$r->platformLabel();
    }

    protected static function approveAction(string $kind): Tables\Actions\Action
    {
        $requested = Account::totpColumn($kind, 'totp_requested_at');
        $extra     = Account::totpColumn($kind, 'totp_extra_allowed');

        return Tables\Actions\Action::make('approve_'.$kind)
            ->label(fn (Account $r): string => 'وافق على '.self::kindLabel($r, $kind))
            ->icon('heroicon-o-key')
            ->color('primary')
            ->visible(fn (Account $r): bool => $r->hasPendingTotpRequest($kind))
            ->requiresConfirmation()
            ->modalDescription('سيُسمح بتوليد كود واحد إضافي لهذا الحساب من رابط التسليم.')
            ->action(function (Account $record) use ($requested, $extra): void {
                // Row lock so a double click can't grant two codes.
                $granted = DB::transaction(function () use ($record, $requested, $extra): bool {
                    $locked = Account::query()->lockForUpdate()->find($record->id);
                    if ($locked === null || $locked->{$requested} === null) {
                        return false;
                    }

                    $locked->update([
                        $extra     => (int) $locked->{$extra} + 1,
                        $requested => null,
                    ]);

                    return true;
                });

                if (! $granted) {
                    Notification::make()->warning()->title('تمت معالجة هذا الطلب مسبقاً')->send();
                    return;
                }

                Notification::make()->success()->title('تمت الموافقة — يقدر يولّد كوداً إضافياً الآن')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTotpRequests::route('/'),
        ];
    }
}
