<?php

namespace App\Filament\Resources\FundAllocations\Pages;

use App\Filament\Resources\FundAllocations\FundAllocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundAllocations extends ListRecords
{
    protected static string $resource = FundAllocationResource::class;

    protected static ?string $title = 'Alocações por Competência';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-allocations-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Distribuição mensal do saldo de cada aplicação física entre os fundos vinculados, com revisão e aprovação governadas.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova alocação')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
