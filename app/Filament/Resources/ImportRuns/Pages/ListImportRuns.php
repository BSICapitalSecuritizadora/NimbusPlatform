<?php

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListImportRuns extends ListRecords
{
    protected static string $resource = ImportRunResource::class;

    protected static ?string $title = 'Histórico de Importações';

    protected static ?string $breadcrumb = 'Listar';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * No header action on purpose: an import run is created by confirming an
     * import, never from here.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
