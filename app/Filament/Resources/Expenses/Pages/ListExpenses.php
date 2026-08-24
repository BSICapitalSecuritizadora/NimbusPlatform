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

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-expenses-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhamento das despesas previstas, recorrentes e vencimentos das operações.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')
                ->label('Calendário')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (ListExpenses $livewire): string => ExpenseCalendar::getUrl(static::calendarContext($livewire))),
            CreateAction::make()
                ->label('Cadastrar despesa')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }

    /**
     * Carrega para o calendário o contexto de filtros da listagem, quando for
     * possível representá-lo sem perda: categoria (valor único) e operação
     * (apenas quando exatamente uma estiver selecionada).
     *
     * @return array<string, string>
     */
    protected static function calendarContext(ListExpenses $livewire): array
    {
        $filters = $livewire->tableFilters ?? [];
        $context = [];

        $category = $filters['category']['value'] ?? null;

        if (filled($category)) {
            $context['category'] = (string) $category;
        }

        $emissions = array_filter((array) ($filters['emission_id']['values'] ?? []));

        if (count($emissions) === 1) {
            $context['emission_id'] = (string) reset($emissions);
        }

        return $context;
    }
}
