<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WipeLogResource\Pages;

use App\Filament\App\Resources\WipeLogResource;
use Filament\Resources\Pages\ListRecords;

class ListWipeLogs extends ListRecords
{
    protected static string $resource = WipeLogResource::class;
}
