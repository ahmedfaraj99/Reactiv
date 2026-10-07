<?php

namespace App\Filament\App\Resources\AccountResource\Pages;

use App\Filament\App\Concerns\HasMatchesTypeSwitch;
use App\Filament\App\Resources\AccountResource;
use App\Models\AccountAssignment;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAccounts extends ListRecords
{
    use HasMatchesTypeSwitch;

    protected static string $resource = AccountResource::class;

    protected static string $view = 'filament.app.resources.account-resource.pages.list-accounts';

    /**
     * The resource query narrowed by the matches-type switch. Both the
     * table and the status-tab badges go through this, so "فشل (3)" under
     * "بمباريات" means three failed match accounts, not three overall.
     */
    protected function typedQuery(): Builder
    {
        return AccountResource::getEloquentQuery()->ofMatchesType($this->activeMatchesType());
    }

    protected function getTableQuery(): ?Builder
    {
        return $this->typedQuery();
    }

    public function getMatchesTypeCounts(): array
    {
        return [
            'all'        => AccountResource::getEloquentQuery()->count(),
            'matches'    => AccountResource::getEloquentQuery()->ofMatchesType('matches')->count(),
            'activation' => AccountResource::getEloquentQuery()->ofMatchesType('activation')->count(),
        ];
    }

    /**
     * One-click views instead of digging through the filter dropdown —
     * "فشل" in particular needs to be an obvious, prominent tab since
     * that's the workflow that needs frequent, quick attention.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('الكل')
                ->badge(fn () => $this->typedQuery()->count()),

            'available' => Tab::make('متاح')
                ->badge(fn () => $this->typedQuery()->where('status', 'available')->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'available')),

            'failed' => Tab::make('فشل')
                ->badge(fn () => $this->typedQuery()
                    ->where('status', 'assigned')
                    ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_FAILED))
                    ->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'assigned')
                    ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_FAILED))),

            'awaiting_review' => Tab::make('بانتظار المراجعة')
                ->badge(fn () => $this->typedQuery()
                    ->where('status', 'assigned')
                    ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_AWAITING_REVIEW))
                    ->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'assigned')
                    ->whereHas('assignment', fn ($a) => $a->where('status', AccountAssignment::STATUS_AWAITING_REVIEW))),

            'activated' => Tab::make('مكتمل')
                ->badge(fn () => $this->typedQuery()->where('status', 'activated')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'activated')),

            'retired' => Tab::make('مؤرشف')
                ->badge(fn () => $this->typedQuery()->where('status', 'retired')->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'retired')),
        ];
    }
}
