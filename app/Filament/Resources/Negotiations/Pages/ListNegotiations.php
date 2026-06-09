<?php

namespace App\Filament\Resources\Negotiations\Pages;

use App\Filament\Resources\Negotiations\NegotiationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNegotiations extends ListRecords
{
    protected static string $resource = NegotiationResource::class;

    protected static ?string $title = 'Negociações';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Cadastrar Negociação')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
