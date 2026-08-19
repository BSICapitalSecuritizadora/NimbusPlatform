<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Filament\Resources\Emissions\EmissionResource;
use App\Models\Emission;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEmissions extends ListRecords
{
    protected static string $resource = EmissionResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-emissions-list-page',
    ];

    public function getTitle(): string
    {
        return 'Emissões';
    }

    public function getSubheading(): ?string
    {
        return 'Gestão institucional das operações emitidas, séries, identificadores e status de distribuição.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Emissão')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }

    public function getTabs(): array
    {
        $counts = Emission::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $totalCount = array_sum($counts);
        $draftCount = (int) ($counts['draft'] ?? 0);
        $activeCount = (int) ($counts['active'] ?? 0);
        $closedCount = (int) ($counts['closed'] ?? 0);
        $defaultCount = (int) ($counts['default'] ?? 0);

        return [
            'all' => Tab::make('Todas')
                ->badge($totalCount)
                ->badgeColor('gray'),
            'draft' => Tab::make('Em Elaboração')
                ->badge($draftCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft')),
            'active' => Tab::make('Em Distribuição')
                ->badge($activeCount)
                ->badgeColor($activeCount > 0 ? 'success' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')),
            'closed' => Tab::make('Liquidada')
                ->badge($closedCount)
                ->badgeColor($closedCount > 0 ? 'info' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'closed')),
            'default' => Tab::make('Default')
                ->badge($defaultCount)
                ->badgeColor($defaultCount > 0 ? 'danger' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'default')),
        ];
    }
}
