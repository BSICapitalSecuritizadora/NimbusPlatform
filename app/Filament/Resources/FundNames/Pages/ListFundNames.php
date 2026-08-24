<?php

namespace App\Filament\Resources\FundNames\Pages;

use App\Filament\Resources\FundNames\FundNameResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundNames extends ListRecords
{
    protected static string $resource = FundNameResource::class;

    protected static ?string $title = 'Nomes de Fundo';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-names-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie as denominações disponíveis para cada tipo de fundo.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar nome de fundo')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
