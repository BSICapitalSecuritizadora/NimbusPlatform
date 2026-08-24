<?php

namespace App\Filament\Resources\Operations\Pages;

use App\Filament\Resources\Operations\OperationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

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

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Visualizar')
                ->icon('heroicon-m-eye')
                ->color('gray'),
            DeleteAction::make()
                ->label('Excluir operação'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->developments = $data['developments'] ?? [];
        unset($data['developments']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncDevelopmentPlans($this->developments);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Operação de obra atualizada com sucesso.';
    }
}
