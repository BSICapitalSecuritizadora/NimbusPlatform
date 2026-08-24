<?php

namespace App\Filament\Resources\Funds\Pages;

use App\Filament\Resources\Funds\FundResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFunds extends ListRecords
{
    protected static string $resource = FundResource::class;

    protected static ?string $title = 'Fundos';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-funds-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhamento dos fundos vinculados às operações, com identificação, aplicação e dados bancários.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar fundo')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
