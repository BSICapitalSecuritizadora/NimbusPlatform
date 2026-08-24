<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Filament\Resources\Measurements\MeasurementResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditMeasurement extends EditRecord
{
    protected static string $resource = MeasurementResource::class;

    protected static ?string $title = 'Editar Medição';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize os dados e arquivos da medição da operação.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-measurement-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Visualizar')
                ->icon('heroicon-m-eye')
                ->color('gray'),
            DeleteAction::make()
                ->label('Excluir medição'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Medição atualizada com sucesso.';
    }
}
