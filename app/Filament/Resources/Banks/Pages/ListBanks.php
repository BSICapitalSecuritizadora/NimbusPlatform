<?php

namespace App\Filament\Resources\Banks\Pages;

use App\Filament\Resources\Banks\BankResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBanks extends ListRecords
{
    protected static string $resource = BankResource::class;

    protected static ?string $title = 'Bancos';

    protected static ?string $breadcrumb = 'Listar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-banks-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie as instituições bancárias utilizadas nos fundos e contas financeiras das operações.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar banco')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
