<?php

namespace App\Filament\Resources\BankApplications\Pages;

use App\Filament\Resources\BankApplications\BankApplicationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankApplications extends ListRecords
{
    protected static string $resource = BankApplicationResource::class;

    protected static ?string $title = 'Aplicações Bancárias';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-bank-applications-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Produtos bancários de cada conta física, identificados pelo código do produto informado pelo banco.';
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
