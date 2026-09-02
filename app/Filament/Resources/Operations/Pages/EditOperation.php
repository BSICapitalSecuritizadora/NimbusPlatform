<?php

namespace App\Filament\Resources\Operations\Pages;

use App\Filament\Resources\Operations\OperationResource;
use App\Models\Operation;
use App\Models\User;
use App\Services\OperationContextMutationService;
use App\Services\OperationContextVisibilityService;
use App\Services\OperationResponsibilityService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOperation extends EditRecord
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Editar Operação de Obra';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize a operação, os empreendimentos e os responsáveis pelo fluxo de medição.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-operation-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $developments = [];

    /**
     * Exclusão física saiu daqui: o ciclo de vida é Ativar / Concluir / Cancelar
     * / Reabrir, e cada uma dessas ações preserva o histórico em vez de apagá-lo.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Visualizar')
                ->icon('heroicon-m-eye')
                ->color('gray'),
            OperationResource::getActivateOperationAction()->record($this->record),
            OperationResource::getCompleteOperationAction()->record($this->record),
            OperationResource::getCancelOperationAction()->record($this->record),
            OperationResource::getReopenOperationAction()->record($this->record),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        app(OperationResponsibilityService::class)->assertCanChange($actor, $this->record, $data);

        $this->developments = $data['developments'] ?? [];
        app(OperationContextVisibilityService::class)->assertOperationPayloadIsVisible(
            $actor,
            $data['emission_id'] ?? $this->record->emission_id,
            $this->developments,
        );
        unset($data['developments']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Operation) {
            return parent::handleRecordUpdate($record, $data);
        }

        return app(OperationContextMutationService::class)->update($record, $data);
    }

    protected function afterSave(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $this->record->syncDevelopmentPlans($this->developments, $actor);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Operação de obra atualizada com sucesso.';
    }
}
