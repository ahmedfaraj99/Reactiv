<?php

namespace App\Filament\App\Resources\RevealLogResource\Pages;

use App\Filament\App\Resources\RevealLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRevealLogs extends ListRecords
{
    protected static string $resource = RevealLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
