<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Receivable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateReceivable extends CreateRecord
{
    protected static string $resource = ReceivableResource::class;

    protected static ?string $title = 'Cadastrar Resumo de Recebíveis';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $exists = Receivable::query()
            ->where('emission_id', $data['emission_id'] ?? null)
            ->whereDate('reference_month', $data['reference_month'] ?? null)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'reference_month' => 'Já existe um resumo de recebíveis para esta operação e competência.',
            ]);
        }

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Resumo de recebíveis cadastrado com sucesso.';
    }
}
