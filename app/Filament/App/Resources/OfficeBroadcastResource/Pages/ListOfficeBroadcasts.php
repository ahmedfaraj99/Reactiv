<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\OfficeBroadcastResource\Pages;

use App\Filament\App\Resources\OfficeBroadcastResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOfficeBroadcasts extends ListRecords
{
    protected static string $resource = OfficeBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إعلان جديد'),
        ];
    }
}
