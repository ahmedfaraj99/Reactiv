<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ClubCreation\TotpRequestResource\Pages;

use App\Filament\App\Resources\ClubCreation\TotpRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListTotpRequests extends ListRecords
{
    protected static string $resource = TotpRequestResource::class;
}
