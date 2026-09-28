<?php

namespace App\Filament\Resources\Areas\Pages;

use App\Filament\Resources\Areas\AreaResource;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageAreas extends ManageRecords
{
    protected static string $resource = AreaResource::class;

    protected static ?string $title = 'Áreas e Responsáveis';

    protected Width|string|null $maxContentWidth = Width::SixExtraLarge;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-areas-page',
    ];

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SixExtraLarge;
    }

    public function getHeading(): string
    {
        return 'Áreas e Responsáveis';
    }

    public function getSubheading(): ?string
    {
        return 'Defina os responsáveis por cada área e consulte as regras e permissões associadas.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
