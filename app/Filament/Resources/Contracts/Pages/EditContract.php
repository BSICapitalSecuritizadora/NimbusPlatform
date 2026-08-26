<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    protected static ?string $title = 'Editar Contrato';

    protected static ?string $breadcrumb = 'Editar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Visualizar'),
            DeleteAction::make()
                ->label('Excluir')
                ->modalHeading('Excluir contrato')
                ->modalDescription('O contrato deixa de aparecer na listagem e libera a unidade, mas é preservado para manter o histórico comercial.'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Contrato atualizado com sucesso.';
    }
}
