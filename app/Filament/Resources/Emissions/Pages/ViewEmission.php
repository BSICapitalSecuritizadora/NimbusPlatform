<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Widgets\PuCalculator\PuBaselineReadinessWidget;
use App\Models\Emission;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewEmission extends ViewRecord
{
    protected static string $resource = EmissionResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-emission-view-page',
    ];

    public function getTitle(): string|Htmlable
    {
        return $this->record->name ?? "Emissão #{$this->record->id}";
    }

    public function getSubheading(): string|Htmlable|null
    {
        $type = $this->record->type ?? 'Emissão';
        $statusLabel = Emission::STATUS_OPTIONS[$this->record->status] ?? (string) ($this->record->status ?? '—');
        $series = trim("{$this->record->emission_number} / {$this->record->series}", ' /');
        $seriesText = filled($series) ? "Série {$series}" : null;
        $maturity = $this->record->maturity_date ? $this->record->maturity_date->format('d/m/Y') : null;
        $maturityText = $maturity ? "Vencimento em {$maturity}" : null;
        $volume = $this->record->issued_volume !== null
            ? 'R$ '.number_format((float) $this->record->issued_volume, 2, ',', '.')
            : null;

        $parts = array_filter([
            $type,
            $statusLabel,
            $volume,
            $seriesText,
            $maturityText,
        ]);

        return implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-m-pencil-square')
                ->color('gray'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        if (! app(PuBaselineReadinessService::class)->supports($this->getRecord())) {
            return [];
        }

        return [
            PuBaselineReadinessWidget::make([
                'record' => $this->getRecord(),
            ]),
        ];
    }
}
