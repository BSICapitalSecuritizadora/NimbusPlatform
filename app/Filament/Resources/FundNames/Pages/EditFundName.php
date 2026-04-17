<?php

namespace App\Filament\Resources\FundNames\Pages;

use App\Filament\Resources\FundNames\FundNameResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFundName extends EditRecord
{
    protected static string $resource = FundNameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
