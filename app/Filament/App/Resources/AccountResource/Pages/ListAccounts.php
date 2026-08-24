<?php

namespace App\Filament\App\Resources\AccountResource\Pages;

use App\Filament\App\Resources\AccountResource;
use App\Models\AccountAssignment;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    /**
     * One-click views instead of digging through the filter dropdown —
     * "فشل" in particular needs to be an obvious, prominent tab since
     * that's the workflow that needs frequent, quick attention.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('الكل')
                ->badge(fn () => AccountResource::getEloquentQuery()->count()),

            'available' => Tab::make('متاح')
                ->badge(fn () => AccountResource::getEloquentQuery()->where('status', 'available')->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'available')),

            'failed' => Tab::make('فشل')
                ->badge(fn () => AccountResource::getEloquentQuery()
                    ->where('status', 'assigned')
                    ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_FAILED))
                    ->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'assigned')
                    ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_FAILED))),

            'awaiting_review' => Tab::make('بانتظار المراجعة')
                ->badge(fn () => AccountResource::getEloquentQuery()
                    ->where('status', 'assigned')
                    ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_AWAITING_REVIEW))
                    ->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'assigned')
                    ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_AWAITING_REVIEW))),

            'activated' => Tab::make('مكتمل')
                ->badge(fn () => AccountResource::getEloquentQuery()->where('status', 'activated')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'activated')),

            'retired' => Tab::make('مؤرشف')
                ->badge(fn () => AccountResource::getEloquentQuery()->where('status', 'retired')->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'retired')),
        ];
    }
}
