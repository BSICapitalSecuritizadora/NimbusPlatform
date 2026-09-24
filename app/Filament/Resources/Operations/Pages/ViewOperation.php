<?php

namespace App\Filament\Resources\Operations\Pages;

use App\Filament\Resources\Operations\OperationResource;
use App\Models\Operation;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOperation extends ViewRecord
{
    protected static string $resource = OperationResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-operation-view-page',
    ];

    public function getSubheading(): ?string
    {
        /** @var Operation $operation */
        $operation = $this->getRecord();

        return collect([
            $operation->code,
            $operation->emission?->name,
            $operation->status->label(),
        ])->filter(fn (?string $value): bool => filled($value))->implode(' · ');
    }

    /**
     * A tela de visualização é o lugar natural do ciclo de vida: quem confere
     * uma operação encerrada aqui é quem decide reabri-la, e quem acompanha uma
     * em rascunho é quem decide ativá-la.
     *
     * A hierarquia visual é proposital: Editar é secundária (contorno dourado),
     * Concluir é positiva (verde discreto) e Cancelar é destrutiva (vermelho).
     * Nomes, modais, confirmações, visibilidade e comportamento seguem intactos.
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->color('primary')
                ->outlined(),
            OperationResource::getActivateOperationAction()->record($this->record),
            OperationResource::getCompleteOperationAction()->record($this->record)->color('success'),
            OperationResource::getCancelOperationAction()->record($this->record),
            OperationResource::getReopenOperationAction()->record($this->record),
        ];
    }
}
