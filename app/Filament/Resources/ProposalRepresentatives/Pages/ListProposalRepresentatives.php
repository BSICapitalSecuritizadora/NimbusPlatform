<?php

namespace App\Filament\Resources\ProposalRepresentatives\Pages;

use App\Filament\Resources\ProposalRepresentatives\ProposalRepresentativeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProposalRepresentatives extends ListRecords
{
    protected static string $resource = ProposalRepresentativeResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-representatives-list-page',
    ];

    public function getTitle(): string
    {
        return 'Representantes Comerciais';
    }

    public function getSubheading(): ?string
    {
        return 'Gestão dos representantes comerciais, vínculos internos e distribuição da carteira.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Representante')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
