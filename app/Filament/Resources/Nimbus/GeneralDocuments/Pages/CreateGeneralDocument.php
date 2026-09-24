<?php

namespace App\Filament\Resources\Nimbus\GeneralDocuments\Pages;

use App\Filament\Resources\Nimbus\GeneralDocuments\GeneralDocumentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class CreateGeneralDocument extends CreateRecord
{
    protected static string $resource = GeneralDocumentResource::class;

    protected static ?string $title = 'Novo Documento Geral';

    protected static ?string $breadcrumb = 'Criar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-document-form-page bsi-general-document-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre um documento institucional e defina sua disponibilidade.';
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
            $this->getCreateFormAction(),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar')
            ->icon('heroicon-m-plus')
            ->color('primary');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outro')
            ->color('gray');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
