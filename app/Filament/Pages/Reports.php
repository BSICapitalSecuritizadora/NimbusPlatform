<?php

namespace App\Filament\Pages;

use App\Models\Emission;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Reports extends Page
{
    public ?int $emissionId = null;

    public string $referenceMonth = '';

    public string $referenceMonthEnd = '';

    protected string $view = 'filament.pages.reports';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Emissões';

    protected static ?int $navigationSort = 13;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Relatório Mensal';

    protected static ?string $title = 'Relatórios';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    public function mount(): void
    {
        $this->referenceMonth = CarbonImmutable::now()->format('Y-m');
    }

    public function getSubheading(): ?string
    {
        return 'Geração do relatório institucional mensal das emissões.';
    }

    /**
     * @return array<int, string>
     */
    public function emissionOptions(): array
    {
        return Emission::query()
            ->orderBy('name')
            ->get(['id', 'name', 'if_code', 'isin_code'])
            ->mapWithKeys(function (Emission $emission): array {
                $identifier = $emission->isin_code ?? $emission->if_code;
                $label = $identifier ? sprintf('%s (%s)', $emission->name, $identifier) : $emission->name;

                return [$emission->id => $label];
            })
            ->all();
    }

    public function hasInvalidRange(): bool
    {
        return $this->referenceMonth !== ''
            && $this->referenceMonthEnd !== ''
            && $this->referenceMonthEnd < $this->referenceMonth;
    }

    public function isConsolidated(): bool
    {
        return $this->referenceMonthEnd !== ''
            && $this->referenceMonthEnd !== $this->referenceMonth
            && ! $this->hasInvalidRange();
    }

    public function reportSummary(): ?string
    {
        if ($this->reportUrl() === null) {
            return null;
        }

        $emissionLabel = $this->emissionOptions()[$this->emissionId] ?? null;

        if ($emissionLabel === null) {
            return null;
        }

        $start = CarbonImmutable::createFromFormat('Y-m', $this->referenceMonth);

        $period = $this->monthLabel($start);

        if ($this->isConsolidated()) {
            $end = CarbonImmutable::createFromFormat('Y-m', $this->referenceMonthEnd);

            $period = $this->monthLabel($start).' a '.$this->monthLabel($end);
        }

        return $emissionLabel.' · '.$period;
    }

    private function monthLabel(CarbonImmutable $month): string
    {
        $months = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ];

        return $months[(int) $month->format('n')].' de '.$month->format('Y');
    }

    public function reportUrl(): ?string
    {
        if ($this->emissionId === null || $this->referenceMonth === '' || $this->hasInvalidRange()) {
            return null;
        }

        $parameters = [
            'emission' => $this->emissionId,
            'reference_month' => $this->referenceMonth,
        ];

        if ($this->referenceMonthEnd !== '' && $this->referenceMonthEnd !== $this->referenceMonth) {
            $parameters['reference_month_end'] = $this->referenceMonthEnd;
        }

        return route('admin.emissions.monthly-report.pdf', $parameters);
    }
}
