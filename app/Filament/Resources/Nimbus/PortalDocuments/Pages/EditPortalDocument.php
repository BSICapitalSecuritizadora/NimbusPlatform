<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments\Pages;

use App\Filament\Resources\Nimbus\PortalDocuments\PortalDocumentResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditPortalDocument extends EditRecord
{
    protected static string $resource = PortalDocumentResource::class;

    protected static ?string $title = 'Editar Documento do Usuário';

    protected static ?string $breadcrumb = 'Editar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-portal-document-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Atualize os dados, destinatário ou arquivo do documento.';
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Salvar alterações')
            ->icon('heroicon-m-check')
            ->color('primary');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir')
                ->color('danger'),
        ];
    }
}
