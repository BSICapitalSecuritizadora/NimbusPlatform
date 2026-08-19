<?php

namespace App\Filament\Widgets\PuCalculator;

use App\Support\PuCalculator\PuOperationalDashboardData;
use Filament\Widgets\Widget;

class PuCurveOperationalSummaryWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.pu-calculator.pu-curve-operational-summary-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    /**
     * Indicador atualmente usado como recorte da tabela de curvas.
     */
    public ?string $focusedState = null;

    /**
     * Aplica (ou remove) o recorte correspondente ao indicador clicado na tabela abaixo.
     */
    public function focusState(string $state): void
    {
        $this->focusedState = $this->focusedState === $state ? null : $state;

        $this->dispatch('pu-curves-focus', state: $this->focusedState);
    }

    protected function getViewData(): array
    {
        return app(PuOperationalDashboardData::class)->overview();
    }
}
