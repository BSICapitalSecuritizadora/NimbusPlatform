<?php

namespace App\Filament\Resources\Investors\Pages;

use App\Filament\Resources\Investors\InvestorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvestors extends ListRecords
{
    protected static string $resource = InvestorResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-investors-list-page',
    ];

    public function getTitle(): string
    {
        return 'Investidores';
    }

    public function getSubheading(): ?string
    {
        return 'Gestão institucional dos investidores cadastrados, credenciais de acesso e relacionamento.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar Investidor')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
