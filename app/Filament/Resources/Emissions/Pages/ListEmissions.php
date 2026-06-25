<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Filament\Resources\Emissions\EmissionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmissions extends ListRecords
{
    protected static string $resource = EmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Cadastrar Emissão')
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => \Filament\Schemas\Components\Tabs\Tab::make('Todas'),
            'draft' => \Filament\Schemas\Components\Tabs\Tab::make('Em Elaboração')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'draft')),
            'active' => \Filament\Schemas\Components\Tabs\Tab::make('Em Distribuição')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'active')),
            'closed' => \Filament\Schemas\Components\Tabs\Tab::make('Liquidada')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'closed')),
            'default' => \Filament\Schemas\Components\Tabs\Tab::make('Default')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'default')),
        ];
    }
}
