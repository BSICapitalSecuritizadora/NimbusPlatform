<?php

namespace App\Filament\Resources\Funds\Pages;

use App\Filament\Resources\Funds\FundResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFunds extends ListRecords
{
    protected static string $resource = FundResource::class;

    protected static ?string $title = 'Fundos';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar fundo'),
        ];
    }
}
