<?php

namespace App\Filament\Resources\Nimbus\Submissions\Pages;

use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
