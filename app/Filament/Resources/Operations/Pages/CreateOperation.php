<?php

namespace App\Filament\Resources\Operations\Pages;

use App\Filament\Resources\Operations\OperationResource;
use App\Models\User;
use App\Services\OperationContextVisibilityService;
use App\Services\OperationResponsibilityService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateOperation extends CreateRecord
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Criar Operação de Obra';

    protected static ?string $breadcrumb = 'Criar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-operation-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Configure a operação, os empreendimentos envolvidos e os responsáveis por cada etapa do fluxo de medição.';
    }

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $developments = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        app(OperationResponsibilityService::class)->assertCanAssignOnCreation($actor, $data);

        $this->developments = $data['developments'] ?? [];
        app(OperationContextVisibilityService::class)->assertOperationPayloadIsVisible(
            $actor,
            $data['emission_id'] ?? null,
            $this->developments,
        );
        unset($data['developments']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $this->record->syncDevelopmentPlans($this->developments, $actor);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar Operação de Obra')
            ->icon('heroicon-m-plus');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outra')
            ->color('gray');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Operação de obra criada com sucesso.';
    }
}
