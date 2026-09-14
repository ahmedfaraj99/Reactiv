<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ClubCreation\CompletedResource\Pages;

use App\Filament\App\Resources\ClubCreation\CompletedResource;
use Filament\Resources\Pages\ListRecords;

class ListCompleted extends ListRecords
{
    protected static string $resource = CompletedResource::class;
}
