<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'Criar despesa';

    protected static ?string $breadcrumb = 'Criar';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ExpenseResource::normalizeFormData($data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Despesa criada com sucesso.';
    }
}
