<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Pages;

use App\Filament\Resources\ExpenseServiceProviders\ExpenseServiceProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseServiceProviders extends ListRecords
{
    protected static string $resource = ExpenseServiceProviderResource::class;

    protected static ?string $title = 'Prestadores de serviço';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Cadastrar prestador'),
        ];
    }
}
