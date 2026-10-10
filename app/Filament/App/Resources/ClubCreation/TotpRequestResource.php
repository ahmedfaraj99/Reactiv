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
            ->whereNotNull('totp_requested_at')
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

                Tables\Columns\TextColumn::make('totp_generations')
                    ->label('الأكواد المولّدة')
                    ->formatStateUsing(fn (Account $r): string => $r->totp_generations.'/'.$r->totpAllowance()),

                Tables\Columns\TextColumn::make('totp_requested_at')
                    ->label('وقت الطلب')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('totp_requested_at', 'asc')
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('وافق على كود إضافي')
                    ->icon('heroicon-o-key')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('سيُسمح بتوليد كود واحد إضافي لهذا الحساب من رابط التسليم.')
                    ->action(function (Account $record): void {
                        // Row lock so a double click can't grant two codes.
                        $granted = DB::transaction(function () use ($record): bool {
                            $locked = Account::query()->lockForUpdate()->find($record->id);
                            if ($locked === null || $locked->totp_requested_at === null) {
                                return false;
                            }

                            $locked->update([
                                'totp_extra_allowed' => $locked->totp_extra_allowed + 1,
                                'totp_requested_at'  => null,
                            ]);

                            return true;
                        });

                        if (! $granted) {
                            Notification::make()->warning()->title('تمت معالجة هذا الطلب مسبقاً')->send();
                            return;
                        }

                        Notification::make()->success()->title('تمت الموافقة — يقدر يولّد كوداً إضافياً الآن')->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Account $record): void {
                        $record->update(['totp_requested_at' => null]);

                        Notification::make()->success()->title('رُفض الطلب')->send();
                    }),
            ])
            ->emptyStateIcon('heroicon-o-key')
            ->emptyStateHeading('لا توجد طلبات معلّقة');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTotpRequests::route('/'),
        ];
    }
}
