<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'Despesas';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')
                ->label('Calendário')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(ExpenseCalendar::getUrl()),
            CreateAction::make()
                ->label('Criar despesa'),
        ];
    }
}
