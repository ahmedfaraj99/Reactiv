<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\AssignmentReviewResource\Pages;

use App\Filament\App\Resources\AssignmentReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListAssignmentReviews extends ListRecords
{
    protected static string $resource = AssignmentReviewResource::class;
}
