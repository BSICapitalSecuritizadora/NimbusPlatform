<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Receivable;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class CreateReceivable extends CreateRecord
{
    protected static string $resource = ReceivableResource::class;

    protected static ?string $title = 'Cadastrar resumo de recebíveis';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-receivable-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Registre os indicadores mensais da carteira, fluxo financeiro, inadimplência e risco da operação.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar resumo')
            ->icon(Heroicon::Plus);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $exists = Receivable::query()
            ->where('emission_id', $data['emission_id'] ?? null)
            ->whereDate('reference_month', $data['reference_month'] ?? null)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'reference_month' => 'Já existe um resumo de recebíveis para esta operação e mês.',
            ]);
        }

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Resumo de recebíveis cadastrado com sucesso.';
    }
}
