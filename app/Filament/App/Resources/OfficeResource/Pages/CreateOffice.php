<?php

namespace App\Filament\App\Resources\OfficeResource\Pages;

use App\Filament\App\Resources\OfficeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateOffice extends CreateRecord
{
    protected static string $resource = OfficeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['manager_id'] = auth()->id();

        return $data;
    }
}
