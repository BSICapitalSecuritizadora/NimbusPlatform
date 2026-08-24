<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Services\MeasurementWorkflow;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateMeasurement extends CreateRecord
{
    protected static string $resource = MeasurementResource::class;

    protected static ?string $title = 'Enviar Medição';

    protected static ?string $breadcrumb = 'Enviar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-measurement-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Envie os arquivos da competência para cada empreendimento vinculado à operação.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploaded_by'] = auth()->id();
        $data['uploaded_at'] = now();
        $data['status'] = 'pending';
        $data['current_stage'] = 1;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(MeasurementWorkflow::class)->startReview($this->record->refresh());
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Enviar Medição')
            ->icon('heroicon-m-arrow-up-tray');
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
        return 'Medição enviada e encaminhada para análise.';
    }
}
