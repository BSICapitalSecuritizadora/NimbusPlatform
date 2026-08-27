<?php

namespace App\Filament\Resources\Nimbus\GeneralDocuments\Pages;

use App\Filament\Resources\Nimbus\GeneralDocuments\GeneralDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGeneralDocuments extends ListRecords
{
    protected static string $resource = GeneralDocumentResource::class;

    protected static ?string $title = 'Biblioteca Geral';

    protected static ?string $breadcrumb = 'Listar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-general-documents-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Centralize documentos institucionais e materiais de consulta geral.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo documento geral')
                ->icon('heroicon-o-plus')
                ->color('primary'),
        ];
    }
}
