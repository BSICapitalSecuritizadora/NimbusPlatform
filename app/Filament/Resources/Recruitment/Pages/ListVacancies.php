<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Enums\VacancyStatus;
use App\Filament\Resources\Recruitment\VacancyResource;
use App\Models\Vacancy;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListVacancies extends ListRecords
{
    protected static string $resource = VacancyResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-vacancies-list-page',
    ];

    public function getTitle(): string
    {
        return 'Vagas';
    }

    public function getSubheading(): ?string
    {
        return 'Gestão institucional de oportunidades de carreira, publicação no site e acompanhamento do funil de recrutamento.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Cadastrar Vaga')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }

    public function getTabs(): array
    {
        $counts = Vacancy::query()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as published_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paused_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as closed_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as archived_count
            ', [
                VacancyStatus::Draft->value,
                VacancyStatus::Published->value,
                VacancyStatus::Paused->value,
                VacancyStatus::Closed->value,
                VacancyStatus::Archived->value,
            ])
            ->first();

        $totalCount = (int) ($counts?->total ?? 0);
        $draftCount = (int) ($counts?->draft_count ?? 0);
        $publishedCount = (int) ($counts?->published_count ?? 0);
        $pausedCount = (int) ($counts?->paused_count ?? 0);
        $closedCount = (int) ($counts?->closed_count ?? 0);
        $archivedCount = (int) ($counts?->archived_count ?? 0);

        return [
            'todas' => Tab::make('Todas')
                ->badge($totalCount)
                ->badgeColor('gray'),
            'rascunhos' => Tab::make('Rascunhos')
                ->badge($draftCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Draft->value)),
            'publicadas' => Tab::make('Publicadas')
                ->badge($publishedCount)
                ->badgeColor($publishedCount > 0 ? 'success' : 'gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Published->value)),
            'pausadas' => Tab::make('Pausadas')
                ->badge($pausedCount)
                ->badgeColor($pausedCount > 0 ? 'warning' : 'gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Paused->value)),
            'encerradas' => Tab::make('Encerradas')
                ->badge($closedCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Closed->value)),
            'arquivadas' => Tab::make('Arquivadas')
                ->badge($archivedCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VacancyStatus::Archived->value)),
        ];
    }
}
