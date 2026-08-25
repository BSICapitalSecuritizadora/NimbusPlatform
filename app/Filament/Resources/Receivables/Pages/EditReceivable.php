<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Receivable;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;

class EditReceivable extends EditRecord
{
    protected static string $resource = ReceivableResource::class;

    protected static ?string $title = 'Editar resumo de recebíveis';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-receivable-form-page',
    ];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir Resumo')
                ->modalHeading('Excluir Resumo de Recebíveis'),
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
                'reference_month' => 'Já existe um resumo de recebíveis para esta operação e mês.',
            ]);
        }

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Resumo de recebíveis atualizado com sucesso.';
    }
}
