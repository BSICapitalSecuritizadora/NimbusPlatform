<?php

namespace App\Filament\Resources\FundTypes\Pages;

use App\Filament\Resources\FundTypes\FundTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundTypes extends ListRecords
{
    protected static string $resource = FundTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
