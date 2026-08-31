<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Filament\Widgets\Recruitment\JobApplicationsOverview;
use App\Models\JobApplication;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListJobApplications extends ListRecords
{
    protected static string $resource = JobApplicationResource::class;

    protected static ?string $title = 'Candidaturas';

    protected static ?string $breadcrumb = 'Listar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-job-applications-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gestão centralizada de inscrições, triagem de talentos e movimentações de candidatos no processo seletivo da BSI.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            JobApplicationsOverview::class,
        ];
    }

    public function getTabs(): array
    {
        $counts = JobApplication::query()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as new_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as screening_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as interview_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as finalist_count,
                SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as closed_count
            ', [
                JobApplication::STATUS_NEW,
                JobApplication::STATUS_SCREENING,
                JobApplication::STATUS_INTERVIEW,
                JobApplication::STATUS_FINALIST,
                JobApplication::STATUS_HIRED,
                JobApplication::STATUS_REJECTED,
            ])
            ->first();

        $totalCount = (int) ($counts?->total ?? 0);
        $newCount = (int) ($counts?->new_count ?? 0);
        $screeningCount = (int) ($counts?->screening_count ?? 0);
        $interviewCount = (int) ($counts?->interview_count ?? 0);
        $finalistCount = (int) ($counts?->finalist_count ?? 0);
        $closedCount = (int) ($counts?->closed_count ?? 0);

        return [
            'todas' => Tab::make('Todas as Candidaturas')
                ->badge($totalCount),
            'novas' => Tab::make('Novas')
                ->badge($newCount)
                ->badgeColor($newCount > 0 ? 'warning' : 'gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_NEW)),
            'triagem' => Tab::make('Em Triagem')
                ->badge($screeningCount)
                ->badgeColor($screeningCount > 0 ? 'info' : 'gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_SCREENING)),
            'entrevista' => Tab::make('Em Entrevista')
                ->badge($interviewCount)
                ->badgeColor($interviewCount > 0 ? 'primary' : 'gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_INTERVIEW)),
            'finalistas' => Tab::make('Finalistas')
                ->badge($finalistCount)
                ->badgeColor($finalistCount > 0 ? 'success' : 'gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_FINALIST)),
            'encerradas' => Tab::make('Encerradas / Histórico')
                ->badge($closedCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    JobApplication::STATUS_HIRED,
                    JobApplication::STATUS_REJECTED,
                ])),
        ];
    }
}
