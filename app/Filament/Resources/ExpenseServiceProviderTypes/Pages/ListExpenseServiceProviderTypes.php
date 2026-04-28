<?php

namespace App\Filament\Resources\ExpenseServiceProviderTypes\Pages;

use App\Filament\Resources\ExpenseServiceProviderTypes\ExpenseServiceProviderTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseServiceProviderTypes extends ListRecords
{
    protected static string $resource = ExpenseServiceProviderTypeResource::class;

    protected static ?string $title = 'Tipos de prestador de serviço';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Cadastrar tipo'),
        ];
    }
}
