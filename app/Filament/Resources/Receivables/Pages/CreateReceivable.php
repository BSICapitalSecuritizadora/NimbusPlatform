<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Receivable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateReceivable extends CreateRecord
{
    protected static string $resource = ReceivableResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $exists = Receivable::query()
            ->where('emission_id', $data['emission_id'] ?? null)
            ->whereDate('reference_month', $data['reference_month'] ?? null)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'reference_month' => 'Ja existe um resumo de recebiveis para esta emissao e competencia.',
            ]);
        }

        return $data;
    }
}
