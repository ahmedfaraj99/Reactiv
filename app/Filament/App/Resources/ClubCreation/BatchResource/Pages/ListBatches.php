<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ClubCreation\BatchResource\Pages;

use App\Filament\App\Resources\ClubCreation\BatchResource;
use Filament\Resources\Pages\ListRecords;

class ListBatches extends ListRecords
{
    protected static string $resource = BatchResource::class;
}
