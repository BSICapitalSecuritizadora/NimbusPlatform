<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Receivable;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditReceivable extends EditRecord
{
    protected static string $resource = ReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $exists = Receivable::query()
            ->where('emission_id', $data['emission_id'] ?? null)
            ->whereDate('reference_month', $data['reference_month'] ?? null)
            ->whereKeyNot($this->record->getKey())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'reference_month' => 'Ja existe um resumo de recebiveis para esta emissao e competencia.',
            ]);
        }

        return $data;
    }
}
