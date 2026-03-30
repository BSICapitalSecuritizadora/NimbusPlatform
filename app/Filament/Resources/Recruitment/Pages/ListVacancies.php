<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Filament\Resources\Recruitment\VacancyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListVacancies extends ListRecords
{
    protected static string $resource = VacancyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'todas' => \Filament\Schemas\Components\Tabs\Tab::make('Todas'),
            'abertas' => \Filament\Schemas\Components\Tabs\Tab::make('Abertas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true)),
            'pausadas' => \Filament\Schemas\Components\Tabs\Tab::make('Pausadas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),
        ];
    }
}
