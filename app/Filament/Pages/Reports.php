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

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?int $navigationSort = 40;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Relatórios';

    protected static ?string $title = 'Relatórios';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    public function mount(): void
    {
        $this->referenceMonth = CarbonImmutable::now()->format('Y-m');
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

    public function reportUrl(): ?string
    {
        if ($this->emissionId === null || $this->referenceMonth === '') {
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
