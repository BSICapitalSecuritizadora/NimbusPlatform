<?php

namespace App\Filament\Resources\IndexProjectionSeriesResources\Pages;

use App\Filament\Resources\IndexProjectionSeriesResources\IndexProjectionSeriesResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListIndexProjectionSeries extends ListRecords
{
    protected static string $resource = IndexProjectionSeriesResource::class;

    protected static ?string $title = 'Séries Projetadas';

    protected static ?string $breadcrumb = 'Listar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-index-projection-series-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhe as versões, referências e aprovações das séries projetadas utilizadas pela plataforma.';
    }
}
