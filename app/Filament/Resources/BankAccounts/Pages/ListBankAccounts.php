<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankAccounts extends ListRecords
{
    protected static string $resource = BankAccountResource::class;

    protected static ?string $title = 'Contas Bancárias';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-bank-accounts-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Contas físicas globais: cada conta pode atender a várias operações e abrigar várias aplicações.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar conta')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
