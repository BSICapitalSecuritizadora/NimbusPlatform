<?php

namespace App\Filament\Resources\Recruitment\Infolists;

use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyStatus;
use App\Enums\VacancyWorkModel;
use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Models\JobApplication;
use App\Models\Vacancy;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class VacancyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->schema([
                    TextEntry::make('title')->label('Título')->weight('bold')->size('lg')->columnSpanFull(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => VacancyStatus::labelFor($state))
                        ->color(fn ($state): string => VacancyStatus::colorFor($state)),
                    TextEntry::make('department')->label('Departamento')->badge()->color('gray')->placeholder('Geral'),
                    TextEntry::make('type')->label('Tipo de Contratação')->badge()->color('gray')->formatStateUsing(fn (?string $state): string => VacancyEmploymentType::labelFor($state)),
                    TextEntry::make('work_model')
                        ->label('Modelo de Trabalho')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => VacancyWorkModel::labelFor($state))
                        ->placeholder('—'),
                    TextEntry::make('location')->label('Localização')->placeholder('—'),
                    TextEntry::make('positions')->label('Vagas Disponíveis')->badge()->color('gray'),
                    TextEntry::make('hiringManager.name')->label('Gestor Responsável')->placeholder('—'),
                    TextEntry::make('slug')->label('URL (Slug)')->copyable()->placeholder('—'),
                ])
                ->columns(3),

            Section::make('Publicação')
                ->schema([
                    TextEntry::make('published_at')->label('Publicada em')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('expires_at')->label('Expira em')->date('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('closed_at')->label('Encerrada em')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('visibility')
                        ->label('Visibilidade no Site')
                        ->state(fn (Vacancy $record): string => $record->isVisibleForSite() ? 'Visível publicamente' : 'Não visível')
                        ->badge()
                        ->color(fn (Vacancy $record): string => $record->isVisibleForSite() ? 'success' : 'gray'),
                    TextEntry::make('created_at')->label('Cadastrada em')->dateTime('d/m/Y H:i'),
                    TextEntry::make('updated_at')->label('Atualizada em')->dateTime('d/m/Y H:i'),
                ])
                ->columns(3),

            Section::make('Recrutamento — Pipeline')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('applications_total')
                            ->label('Total')
                            ->state(function (Vacancy $record): int {
                                $counts = $record->applicationCountsByStatus();

                                return array_sum($counts);
                            })
                            ->badge()->color('gray')->url(fn (Vacancy $record): string => JobApplicationResource::getUrl('index', ['tableFilters[vacancy_id][value]' => $record->getKey()])),
                        TextEntry::make('pipeline_nova')
                            ->label('Novas')
                            ->state(fn (Vacancy $record): int => $record->applicationCountsByStatus()[JobApplication::STATUS_NEW] ?? 0)
                            ->badge()->color(JobApplication::statusColorFor(JobApplication::STATUS_NEW))
                            ->url(fn (Vacancy $record): string => JobApplicationResource::getUrl('index', ['tableFilters[vacancy_id][value]' => $record->getKey(), 'tableFilters[status][value]' => JobApplication::STATUS_NEW])),
                        TextEntry::make('pipeline_triagem')
                            ->label('Triagem')
                            ->state(fn (Vacancy $record): int => $record->applicationCountsByStatus()[JobApplication::STATUS_SCREENING] ?? 0)
                            ->badge()->color(JobApplication::statusColorFor(JobApplication::STATUS_SCREENING))
                            ->url(fn (Vacancy $record): string => JobApplicationResource::getUrl('index', ['tableFilters[vacancy_id][value]' => $record->getKey(), 'tableFilters[status][value]' => JobApplication::STATUS_SCREENING])),
                        TextEntry::make('pipeline_entrevista')
                            ->label('Entrevista')
                            ->state(fn (Vacancy $record): int => $record->applicationCountsByStatus()[JobApplication::STATUS_INTERVIEW] ?? 0)
                            ->badge()->color(JobApplication::statusColorFor(JobApplication::STATUS_INTERVIEW))
                            ->url(fn (Vacancy $record): string => JobApplicationResource::getUrl('index', ['tableFilters[vacancy_id][value]' => $record->getKey(), 'tableFilters[status][value]' => JobApplication::STATUS_INTERVIEW])),
                        TextEntry::make('pipeline_finalista')
                            ->label('Finalistas')
                            ->state(fn (Vacancy $record): int => $record->applicationCountsByStatus()[JobApplication::STATUS_FINALIST] ?? 0)
                            ->badge()->color(JobApplication::statusColorFor(JobApplication::STATUS_FINALIST))
                            ->url(fn (Vacancy $record): string => JobApplicationResource::getUrl('index', ['tableFilters[vacancy_id][value]' => $record->getKey(), 'tableFilters[status][value]' => JobApplication::STATUS_FINALIST])),
                        TextEntry::make('pipeline_contratada')
                            ->label('Contratadas')
                            ->state(fn (Vacancy $record): int => $record->applicationCountsByStatus()[JobApplication::STATUS_HIRED] ?? 0)
                            ->badge()->color(JobApplication::statusColorFor(JobApplication::STATUS_HIRED))
                            ->url(fn (Vacancy $record): string => JobApplicationResource::getUrl('index', ['tableFilters[vacancy_id][value]' => $record->getKey(), 'tableFilters[status][value]' => JobApplication::STATUS_HIRED])),
                        TextEntry::make('pipeline_reprovada')
                            ->label('Reprovadas')
                            ->state(fn (Vacancy $record): int => $record->applicationCountsByStatus()[JobApplication::STATUS_REJECTED] ?? 0)
                            ->badge()->color(JobApplication::statusColorFor(JobApplication::STATUS_REJECTED))
                            ->url(fn (Vacancy $record): string => JobApplicationResource::getUrl('index', ['tableFilters[vacancy_id][value]' => $record->getKey(), 'tableFilters[status][value]' => JobApplication::STATUS_REJECTED])),
                    ]),
                ]),

            Section::make('Remuneração')
                ->schema([
                    TextEntry::make('salary_range')
                        ->label('Faixa Salarial')
                        ->state(fn (Vacancy $record): string => $record->salaryRangeLabel() ?? '—')
                        ->placeholder('—'),
                    TextEntry::make('salary_visible')
                        ->label('Visível no Site')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Sim' : 'Não (somente interno)')
                        ->badge()
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                ])
                ->columns(2),

            Section::make('Informações Internas')
                ->schema([
                    TextEntry::make('internal_notes')
                        ->label('Observações Internas')
                        ->html()
                        ->placeholder('Sem observações registradas.')
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            Section::make('Conteúdo Público')
                ->schema([
                    TextEntry::make('description')
                        ->label('Descrição')
                        ->html()
                        ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString($state ?? '—'))
                        ->columnSpanFull(),
                    TextEntry::make('requirements')
                        ->label('Requisitos e Qualificações')
                        ->html()
                        ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString($state ?? '—'))
                        ->columnSpanFull(),
                    TextEntry::make('benefits')
                        ->label('Benefícios')
                        ->html()
                        ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString($state ?? '—'))
                        ->columnSpanFull(),
                ])
                ->collapsible(),
        ]);
    }
}
