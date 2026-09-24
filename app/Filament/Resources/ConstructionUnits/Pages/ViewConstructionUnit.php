<?php

namespace App\Filament\Resources\ConstructionUnits\Pages;

use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Models\ConstructionUnit;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewConstructionUnit extends ViewRecord
{
    protected static string $resource = ConstructionUnitResource::class;

    protected static ?string $title = 'Visualizar Unidade';

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-construction-unit-view-page',
    ];

    public function getSubheading(): ?string
    {
        /** @var ConstructionUnit $unit */
        $unit = $this->getRecord();

        return collect([
            $unit->construction?->emission?->name,
            $unit->construction?->development_name,
            filled($unit->block) ? 'Bloco '.$unit->block : null,
            'Unidade '.$unit->unit,
        ])->filter(fn (?string $value): bool => filled($value))->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Editar'),
        ];
    }
}
