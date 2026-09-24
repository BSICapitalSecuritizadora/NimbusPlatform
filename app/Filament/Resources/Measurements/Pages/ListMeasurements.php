<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Filament\Forms\Components\MonthPicker;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Tables\MeasurementsTable;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMeasurements extends ListRecords
{
    protected static string $resource = MeasurementResource::class;

    protected static ?string $title = 'Medições';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-measurements-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhamento das medições enviadas para as operações e empreendimentos vinculados às obras.';
    }

    /**
     * Único ponto em que o estado pendente do painel de filtros vira filtro aplicado
     * ("Aplicar filtros", remoção de indicador, "Limpar filtros"). Intervalo de
     * competência invertido não é aplicado nem corrigido em silêncio.
     */
    public function applyTableFilters(): void
    {
        if (MeasurementsTable::hasInvertedCompetencePeriod($this->getTableFilterFormState('competence_period') ?? [])) {
            Notification::make()
                ->danger()
                ->title('Intervalo de competência inválido')
                ->body(MonthPicker::DEFAULT_RANGE_MESSAGE)
                ->send();

            return;
        }

        parent::applyTableFilters();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Enviar Medição')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
