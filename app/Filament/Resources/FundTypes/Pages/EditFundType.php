<?php

namespace App\Filament\Resources\FundTypes\Pages;

use App\Filament\Resources\FundTypes\FundTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFundType extends EditRecord
{
    protected static string $resource = FundTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
