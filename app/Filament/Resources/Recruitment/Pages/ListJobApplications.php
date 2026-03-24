<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Filament\Resources\Recruitment\JobApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListJobApplications extends ListRecords
{
    protected static string $resource = JobApplicationResource::class;
}
