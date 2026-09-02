<?php

namespace App\Filament\Resources\Negotiations\Pages;

use App\Filament\Resources\Negotiations\NegotiationResource;
use App\Models\Emission;
use App\Services\Reports\NegotiationGlobalProjection;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ListNegotiations extends ListRecords
{
    protected static string $resource = NegotiationResource::class;

    protected static ?string $title = 'Negociações';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-negotiations-list-page',
    ];

    public function getSubheading(): ?string
    {
        // Global projection: inform that automatic negotiations are derived from contracts
        if (app(NegotiationGlobalProjection::class)->shouldUseProjection()) {
            return 'Acompanhamento consolidado de vendas e distratos por empreendimento. As negociações das emissões configuradas como automáticas são geradas a partir dos contratos cadastrados.';
        }

        return 'Acompanhamento consolidado de vendas e distratos por empreendimento.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Negociação')
                ->icon('heroicon-m-plus')
                ->color('primary')
                ->visible(fn (): bool => $this->shouldShowCreateAction()),
        ];
    }

    /**
     * Override table query to return global UNION projection when contracts emissions exist.
     * This fixes the screenshot bug: global listing must show derived rows without needing a filter.
     */
    protected function getTableQuery(): Builder|Relation|null
    {
        $projection = app(NegotiationGlobalProjection::class);

        if ($projection->shouldUseProjection()) {
            // Use global projection that unions contract aggregates + legacy rows
            // Filters (emission_id, etc.) are applied via Filament's filter system on top of this base query,
            // but we also pass current tableFilters for initial scoping
            $filters = $this->tableFilters ?? [];
            // Normalize Filament filter shape to flat ids
            $flat = [];
            foreach (['emission_id', 'construction_id', 'reference_month', 'tipo'] as $key) {
                if (isset($filters[$key])) {
                    $val = $filters[$key];
                    if (is_array($val)) {
                        $val = $val['value'] ?? $val['values'][0] ?? null;
                        if (is_array($val) && isset($val['value'])) {
                            $val = $val['value'];
                        }
                    }
                    $flat[$key] = $val;
                }
            }

            return $projection->eloquentQuery($flat);
        }

        return parent::getTableQuery();
    }

    protected function shouldShowCreateAction(): bool
    {
        if (! NegotiationResource::canCreate()) {
            return false;
        }

        $emissionId = $this->resolveSelectedEmissionId();

        if (filled($emissionId)) {
            $emission = Emission::query()->find($emissionId);

            if ($emission && $emission->usesContractNegotiations()) {
                return false;
            }

            return true;
        }

        // No emission filter: hide if any emission is contracts-mode (all migrated) or if every emission is contracts-mode.
        // To avoid hiding legacy creation when mixed sources exist, only hide when there is no legacy emission.
        $hasContractsMode = Emission::query()->where('negotiations_source', Emission::NEGOTIATIONS_SOURCE_CONTRACTS)->exists();
        $hasLegacy = Emission::query()
            ->where(function ($q): void {
                $q->where('negotiations_source', Emission::NEGOTIATIONS_SOURCE_LEGACY)
                    ->orWhereNull('negotiations_source');
            })->exists();

        if ($hasContractsMode && ! $hasLegacy) {
            return false;
        }

        return true;
    }

    /**
     * Resolve selected emission_id from table filters or request.
     */
    private function resolveSelectedEmissionId(): mixed
    {
        // Livewire tableFilters state is available after mount; check property if exists
        $filters = null;

        if (property_exists($this, 'tableFilters') && filled($this->tableFilters ?? null)) {
            $filters = $this->tableFilters;
        } elseif (isset($this->tableFilters)) {
            $filters = $this->tableFilters;
        }

        // Try Filament's getTableFiltersFormState if available
        if (blank($filters) && method_exists($this, 'getTableFiltersFormState')) {
            try {
                $filters = $this->getTableFiltersFormState();
            } catch (\Throwable) {
                $filters = null;
            }
        }

        $emissionFilter = null;

        if (is_array($filters)) {
            $emissionFilter = $filters['emission_id'] ?? $filters['emission'] ?? null;
        }

        // Also check request query for initial load
        if (blank($emissionFilter)) {
            $emissionFilter = request()->input('tableFilters.emission_id.value')
                ?? request()->input('tableFilters.emission_id')
                ?? request()->query('tableFilters.emission_id.value')
                ?? request()->query('tableFilters.emission_id');
        }

        if (is_array($emissionFilter) && array_key_exists('value', $emissionFilter)) {
            return $emissionFilter['value'];
        }

        if (is_array($emissionFilter) && array_key_exists('values', $emissionFilter)) {
            $vals = $emissionFilter['values'];

            return is_array($vals) ? ($vals[0] ?? null) : $vals;
        }

        if (is_array($emissionFilter) && count($emissionFilter) > 0) {
            // Filament may store as ['value' => X, 'values' => [...]]
            return $emissionFilter['value'] ?? reset($emissionFilter);
        }

        return $emissionFilter;
    }
}
