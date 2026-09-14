<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClubCreation\AccountResource\Pages;

use App\Filament\Resources\ClubCreation\AccountResource;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;
}
