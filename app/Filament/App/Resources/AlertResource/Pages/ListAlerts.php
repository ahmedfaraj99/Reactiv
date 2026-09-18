<?php

namespace App\Filament\App\Resources\AlertResource\Pages;

use App\Enums\AlertType;
use App\Filament\App\Resources\AlertResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAlerts extends ListRecords
{
    protected static string $resource = AlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Quick-access tabs above the alert table. The two approval-request
     * tabs (TOTP + backup codes) are the ones a supervisor/manager
     * spends most of their time on — they need to find and approve
     * pending ones fast without hunting through severity/type filters.
     * The badge shows unresolved counts so a busy office knows at a
     * glance how much is queued.
     */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('غير محلولة')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('resolved', false))
                ->badge(fn () => static::getResource()::getEloquentQuery()->where('resolved', false)->count())
                ->badgeColor('danger'),

            'totp' => Tab::make('طلبات كود إضافي')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('resolved', false)
                    ->where('type', AlertType::TotpLimit))
                ->badge(fn () => static::getResource()::getEloquentQuery()
                    ->where('resolved', false)
                    ->where('type', AlertType::TotpLimit)
                    ->count())
                ->badgeColor('warning'),

            'backup_codes' => Tab::make('طلبات Backup Codes')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('resolved', false)
                    ->where('type', AlertType::BackupCodesReveal))
                ->badge(fn () => static::getResource()::getEloquentQuery()
                    ->where('resolved', false)
                    ->where('type', AlertType::BackupCodesReveal)
                    ->count())
                ->badgeColor('warning'),

            'resolved' => Tab::make('محلولة')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('resolved', true)),

            'all' => Tab::make('الكل'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
