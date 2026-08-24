<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Pages;

use App\Filament\Resources\ExpenseServiceProviders\ExpenseServiceProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseServiceProviders extends ListRecords
{
    protected static string $resource = ExpenseServiceProviderResource::class;

    protected static ?string $title = 'Prestadores de serviço';

    protected static ?string $breadcrumb = 'Listar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-expense-service-providers-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie as empresas e instituições utilizadas como prestadores e participantes das operações.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Cadastrar prestador')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
