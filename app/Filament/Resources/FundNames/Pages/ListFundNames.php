<?php

namespace App\Filament\Resources\FundNames\Pages;

use App\Filament\Resources\FundNames\FundNameResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundNames extends ListRecords
{
    protected static string $resource = FundNameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
