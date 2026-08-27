<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments\Pages;

use App\Filament\Resources\Nimbus\PortalDocuments\PortalDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPortalDocuments extends ListRecords
{
    protected static string $resource = PortalDocumentResource::class;

    protected static ?string $title = 'Documentos por Usuário';

    protected static ?string $breadcrumb = 'Listar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-portal-documents-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie os documentos disponibilizados individualmente aos usuários do portal.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo documento do usuário')
                ->icon('heroicon-o-plus')
                ->color('primary'),
        ];
    }
}
