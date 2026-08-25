<?php

namespace App\Filament\Resources\DocumentDownloads\Pages;

use App\Filament\Exports\DocumentDownloadExporter;
use App\Filament\Resources\DocumentDownloads\DocumentDownloadResource;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentDownloads extends ManageRecords
{
    protected static string $resource = DocumentDownloadResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-document-downloads-list-page',
    ];

    public function getTitle(): string
    {
        return 'Registros de downloads';
    }

    public function getSubheading(): ?string
    {
        return 'Acompanhe downloads realizados no ambiente administrativo e nos canais externos da plataforma.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label('Exportar registros')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->exporter(DocumentDownloadExporter::class),
        ];
    }
}
