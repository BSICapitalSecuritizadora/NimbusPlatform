<?php

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListImportRuns extends ListRecords
{
    protected static string $resource = ImportRunResource::class;

    protected static ?string $title = 'Histórico de importações';

    protected static ?string $breadcrumb = 'Listar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-import-runs-list-page',
    ];

    public function getTitle(): string
    {
        return 'Histórico de importações';
    }

    public function getSubheading(): ?string
    {
        return 'Acompanhe importações processadas, conciliações realizadas e o resultado de cada execução.';
    }

    /**
     * No header action on purpose: an import run is created by confirming an
     * import, never from here.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
