<?php

namespace App\Filament\Resources\Nimbus\DocumentCategories\Pages;

use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateDocumentCategory extends CreateRecord
{
    protected static string $resource = DocumentCategoryResource::class;

    protected static ?string $title = 'Nova Categoria de Documento';

    protected static ?string $breadcrumb = 'Criar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-simple-form-page bsi-document-category-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre uma classificação para organizar os documentos do módulo.';
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
}
