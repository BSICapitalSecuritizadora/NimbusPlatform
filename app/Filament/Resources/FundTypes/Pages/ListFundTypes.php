<?php

namespace App\Filament\Resources\FundTypes\Pages;

use App\Filament\Resources\FundTypes\FundTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundTypes extends ListRecords
{
    protected static string $resource = FundTypeResource::class;

    protected static ?string $title = 'Tipos de Fundo';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-types-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie as classificações utilizadas no cadastro e organização dos fundos das operações.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar tipo de fundo')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
