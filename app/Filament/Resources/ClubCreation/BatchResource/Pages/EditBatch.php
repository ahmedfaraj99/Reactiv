<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClubCreation\BatchResource\Pages;

use App\Filament\Resources\ClubCreation\BatchResource;
use Filament\Resources\Pages\EditRecord;

class EditBatch extends EditRecord
{
    protected static string $resource = BatchResource::class;
}
