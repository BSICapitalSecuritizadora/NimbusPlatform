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
            'all' => \Filament\Resources\Components\Tab::make('Todas'),
            'draft' => \Filament\Resources\Components\Tab::make('Em Elaboração')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'draft')),
            'active' => \Filament\Resources\Components\Tab::make('Em Distribuição')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'active')),
            'closed' => \Filament\Resources\Components\Tab::make('Liquidada')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'closed')),
            'default' => \Filament\Resources\Components\Tab::make('Default')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'default')),
        ];
    }
}
