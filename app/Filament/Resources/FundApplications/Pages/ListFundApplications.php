<?php

namespace App\Filament\Resources\FundApplications\Pages;

use App\Filament\Resources\FundApplications\FundApplicationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundApplications extends ListRecords
{
    protected static string $resource = FundApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
