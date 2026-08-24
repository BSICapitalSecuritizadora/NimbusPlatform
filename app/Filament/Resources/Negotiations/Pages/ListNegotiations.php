<?php

namespace App\Filament\Resources\Negotiations\Pages;

use App\Filament\Resources\Negotiations\NegotiationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNegotiations extends ListRecords
{
    protected static string $resource = NegotiationResource::class;

    protected static ?string $title = 'Negociações';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-negotiations-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhamento consolidado de vendas e distratos por empreendimento.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Negociação')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
