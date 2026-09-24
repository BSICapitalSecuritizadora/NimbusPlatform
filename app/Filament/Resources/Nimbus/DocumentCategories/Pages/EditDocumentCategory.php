<?php

namespace App\Filament\Resources\Nimbus\DocumentCategories\Pages;

use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditDocumentCategory extends EditRecord
{
    protected static string $resource = DocumentCategoryResource::class;

    protected static ?string $title = 'Editar Categoria de Documento';

    protected static ?string $breadcrumb = 'Editar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-simple-form-page bsi-document-category-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Atualize o nome usado para classificar os documentos do módulo.';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir')
                ->color('danger'),
        ];
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
}
