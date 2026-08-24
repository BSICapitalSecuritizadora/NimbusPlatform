<?php

namespace App\Filament\Resources\FundApplications\Pages;

use App\Filament\Resources\FundApplications\FundApplicationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundApplications extends ListRecords
{
    protected static string $resource = FundApplicationResource::class;

    protected static ?string $title = 'Aplicações';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-applications-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie as aplicações utilizadas na classificação financeira dos fundos.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar aplicação')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
