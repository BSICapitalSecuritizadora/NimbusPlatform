<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Enums\VacancyStatus;
use App\Filament\Resources\Recruitment\VacancyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListVacancies extends ListRecords
{
    protected static string $resource = VacancyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Cadastrar Vaga')
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'todas' => Tab::make('Todas'),
            'rascunhos' => Tab::make('Rascunhos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Draft->value)),
            'publicadas' => Tab::make('Publicadas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Published->value)),
            'pausadas' => Tab::make('Pausadas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Paused->value)),
            'encerradas' => Tab::make('Encerradas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Closed->value)),
            'arquivadas' => Tab::make('Arquivadas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Archived->value)),
        ];
    }
}
