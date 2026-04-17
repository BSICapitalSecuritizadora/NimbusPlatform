<?php

namespace App\Filament\Resources\FundApplications\Pages;

use App\Filament\Resources\FundApplications\FundApplicationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFundApplication extends EditRecord
{
    protected static string $resource = FundApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
