<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Enums\ObligationFrequency;
use App\Filament\Exports\ObligationExporter;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use App\Services\Obligations\ObligationSeriesService;
use App\Services\Obligations\ObligationWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ObligationsRelationManager extends RelationManager
{
    protected static string $relationship = 'obligations';

    protected static ?string $title = 'Obrigações';

    protected static ?string $modelLabel = 'Obrigação';

    protected static ?string $pluralModelLabel = 'Obrigações';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsView->value) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(ObligationFormFields::make('obligation'))->columns(2);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dossiê da Obrigação')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('title')
                            ->label('Obrigação')
                            ->weight('bold')
                            ->size('lg')
                            ->columnSpan(2),
                        TextEntry::make('status')
                            ->label('Status Atual')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => Obligation::STATUS_OPTIONS[$state] ?? (string) $state)
                            ->color(fn (?string $state): string => match ($state) {
                                'em_dia', 'concluida' => 'success',
                                'a_vencer' => 'info',
                                'vencida' => 'danger',
                                'em_analise' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('priority')
                            ->label('Prioridade')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => Obligation::PRIORITY_OPTIONS[$state] ?? (string) $state)
                            ->color(fn (?string $state): string => match ($state) {
                                'critical' => 'danger',
                                'high' => 'warning',
                                'medium' => 'info',
                                default => 'gray',
                            }),
                        TextEntry::make('due_date')
                            ->label('Prazo / Vencimento')
                            ->date('d/m/Y')
                            ->placeholder('Sem prazo definido'),
                        TextEntry::make('competence_label')
                            ->label('Competência')
                            ->placeholder('Obrigação única'),
                        TextEntry::make('recurrence')
                            ->label('Recorrência')
                            ->badge()
                            ->placeholder('Única'),
                        TextEntry::make('responsibleUser.name')
                            ->label('Responsável')
                            ->placeholder('Não atribuído'),
                        TextEntry::make('responsible_area')
                            ->label('Área Responsável')
                            ->placeholder('—'),
                        TextEntry::make('source')
                            ->label('Origem')
                            ->state(fn (Obligation $record): string => $this->sourceLabel($record))
                            ->placeholder('—'),
                        TextEntry::make('series.rule_summary')
                            ->label('Regra da série')
                            ->placeholder('Não se aplica')
                            ->columnSpan(2),
                        TextEntry::make('next_action')
                            ->label('Próxima Ação Recomendada')
                            ->state(fn (Obligation $record): string => match ($record->status) {
                                'a_vencer', 'vencida' => 'Anexe evidências e conclua a obrigação quando houver comprovação suficiente.',
                                'em_analise' => 'Revise as evidências anexadas. Apenas evidência aprovada conta como comprovação válida.',
                                'em_dia', 'concluida' => 'Nenhuma ação operacional pendente.',
                                default => 'Defina responsável e organize a comprovação necessária.',
                            })
                            ->color('primary')
                            ->weight('bold')
                            ->columnSpan(2),
                        TextEntry::make('workflow_availability')
                            ->label('Acesso operacional')
                            ->state(fn (Obligation $record): string => $this->workflowAvailabilityMessage($record))
                            ->placeholder('—')
                            ->columnSpan(2),
                    ]),
                    TextEntry::make('description')
                        ->label('Descrição / Detalhes da Obrigação')
                        ->columnSpanFull()
                        ->placeholder('Sem descrição adicional.'),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        $dashboardData = app(ObligationDashboardData::class);
        $canViewEvidence = (bool) (auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value) ?? false);

        $columns = [
            TextColumn::make('title')
                ->label('Título')
                ->state(fn (Obligation $record): string => $record->operational_title)
                ->searchable()
                ->wrap(),
            TextColumn::make('competence_date')
                ->label('Competência')
                ->date('m/Y')
                ->placeholder('Única')
                ->sortable(),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => Obligation::STATUS_OPTIONS[$state] ?? (string) $state)
                ->color(fn (?string $state): string => match ($state) {
                    'em_dia', 'concluida' => 'success',
                    'a_vencer' => 'info',
                    'vencida' => 'danger',
                    'em_analise' => 'warning',
                    default => 'gray',
                }),
            TextColumn::make('due_date')
                ->label('Vencimento')
                ->date('d/m/Y')
                ->placeholder('—')
                ->sortable(),
            TextColumn::make('priority')
                ->label('Prioridade')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => Obligation::PRIORITY_OPTIONS[$state] ?? (string) $state)
                ->color(fn (?string $state): string => match ($state) {
                    'critical' => 'danger',
                    'high' => 'warning',
                    'medium' => 'info',
                    default => 'gray',
                }),
            TextColumn::make('responsibleUser.name')
                ->label('Responsável')
                ->placeholder('Sem responsável')
                ->toggleable(),
            TextColumn::make('responsible_area')
                ->label('Área')
                ->placeholder('Sem área')
                ->toggleable(),
            TextColumn::make('source')
                ->label('Origem')
                ->badge()
                ->state(fn (Obligation $record): string => $this->sourceLabel($record))
                ->color(fn (string $state): string => $state === 'Manual' ? 'gray' : 'info')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('recurrence')
                ->label('Recorrência')
                ->badge()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('obligation_category')
                ->label('Categoria')
                ->badge()
                ->toggleable(isToggledHiddenByDefault: true),
        ];

        if ($canViewEvidence) {
            $columns[] = TextColumn::make('document_status')
                ->label('Situação documental')
                ->state(fn (Obligation $record): string => $dashboardData->documentStatusFor($record))
                ->badge()
                ->color(fn (Obligation $record): string => $dashboardData->documentStatusColorFor($record))
                ->wrap();
            $columns[] = TextColumn::make('evidences_count')
                ->label('Evidências anexadas')
                ->state(fn (Obligation $record): int => (int) ($record->evidences_count ?? 0))
                ->badge()
                ->color('gray');
        }

        $filters = [
            SelectFilter::make('status')
                ->label('Status')
                ->options(Obligation::STATUS_OPTIONS),
            SelectFilter::make('responsible_user_id')
                ->label('Responsável')
                ->relationship('responsibleUser', 'name')
                ->searchable()
                ->preload(),
            SelectFilter::make('responsible_area')
                ->label('Área responsável')
                ->options(fn (): array => collect(Obligation::query()
                    ->whereNotNull('responsible_area')
                    ->orderBy('responsible_area')
                    ->pluck('responsible_area', 'responsible_area')
                    ->all())->union(ObligationFormFields::AREA_OPTIONS)->all()),
            SelectFilter::make('priority')
                ->label('Prioridade')
                ->options(Obligation::PRIORITY_OPTIONS),
            SelectFilter::make('obligation_series_id')
                ->label('Série')
                ->relationship('series', 'title')
                ->searchable()
                ->preload(),
            SelectFilter::make('series_frequency')
                ->label('Recorrência')
                ->options(ObligationFrequency::seriesOptions())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->whereHas('series', fn (Builder $seriesQuery): Builder => $seriesQuery->where('frequency', $data['value']))
                    : $query),
            SelectFilter::make('due_window')
                ->label('Janela de vencimento')
                ->options(ObligationDashboardData::DUE_WINDOW_OPTIONS)
                ->query(fn (Builder $query, array $data): Builder => $dashboardData->applyDueWindowFilter($query, $data['value'] ?? null)),
            TernaryFilter::make('has_responsible')
                ->label('Responsável definido')
                ->placeholder('Todos')
                ->trueLabel('Com responsável')
                ->falseLabel('Sem responsável')
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereNotNull('responsible_user_id'),
                    false: fn (Builder $query): Builder => $query->whereNull('responsible_user_id'),
                    blank: fn (Builder $query): Builder => $query,
                ),
            SelectFilter::make('source')
                ->label('Origem')
                ->options(ObligationDashboardData::SOURCE_FILTER_OPTIONS)
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'series' => $query->whereNotNull('obligation_series_id'),
                        'term' => $query->whereNull('obligation_series_id')->whereNotNull('extracted_obligation_id'),
                        'manual' => $query->whereNull('obligation_series_id')->whereNull('extracted_obligation_id'),
                        default => $query,
                    };
                }),
        ];

        if ($canViewEvidence) {
            $filters[] = SelectFilter::make('evidence_state')
                ->label('Situação documental')
                ->options(ObligationDashboardData::EVIDENCE_FILTER_OPTIONS)
                ->query(fn (Builder $query, array $data): Builder => $dashboardData->applyEvidenceFilter($query, $data['value'] ?? null));
        }

        return $table
            ->recordTitleAttribute('title')
            ->description($this->obligationsTableDescription())
            ->modifyQueryUsing(function (Builder $query) use ($canViewEvidence): Builder {
                $query->with(['responsibleUser', 'series']);

                if (! $canViewEvidence) {
                    return $query;
                }

                return $query->withCount([
                    'evidences',
                    'evidences as approved_evidences_count' => fn (Builder $evidenceQuery): Builder => $evidenceQuery->approved(),
                    'evidences as pending_evidences_count' => fn (Builder $evidenceQuery): Builder => $evidenceQuery->pending(),
                    'evidences as rejected_evidences_count' => fn (Builder $evidenceQuery): Builder => $evidenceQuery->rejected(),
                ]);
            })
            ->columns($columns)
            ->defaultSort('due_date', 'asc')
            ->filters($filters)
            ->headerActions([
                CreateAction::make()
                    ->label('Cadastrar obrigação')
                    ->authorize(fn (): bool => $this->canCreateObligations()),
                ExportAction::make()
                    ->label('Exportar obrigações desta emissão')
                    ->authorize(fn (): bool => $this->canExportObligations())
                    ->columnMapping(false)
                    ->exporter(ObligationExporter::class),
            ])
            ->actions([
                ViewAction::make()
                    ->label('Abrir dossiê')
                    ->modalHeading(fn (Obligation $record): string => 'Visualizar '.$record->operational_title)
                    ->color('info')
                    ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::ObligationsView->value) ?? false)
                    ->extraModalFooterActions(fn (Obligation $record) => [
                        $this->makeSubmitForReviewAction()->record($record)->visible(fn () => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_SUBMIT_FOR_REVIEW)),
                        $this->makeCompleteAction()->record($record)->visible(fn () => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_COMPLETE)),
                        $this->makeMarkNotApplicableAction()->record($record)->visible(fn () => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_MARK_NOT_APPLICABLE)),
                        $this->makeReopenAction()->record($record)->visible(fn () => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_REOPEN)),
                    ]),
                $this->makeCommentsAction(),
                $this->makeHistoryAction(),
                $this->makeSubmitForReviewAction(),
                $this->makeCompleteAction(),
                $this->makeMarkNotApplicableAction(),
                $this->makeReopenAction(),
                EditAction::make()
                    ->label(fn (Obligation $record): string => $record->is_recurring_occurrence ? 'Editar esta competência' : 'Editar obrigação')
                    ->tooltip(fn (Obligation $record): string => $record->is_recurring_occurrence ? 'Editar somente esta competência' : 'Editar obrigação')
                    ->iconButton()
                    ->authorize(fn (): bool => $this->canEditObligations()),
                DeleteAction::make()
                    ->label('Remover obrigação')
                    ->tooltip('Remover obrigação')
                    ->iconButton()
                    ->visible(fn (Obligation $record): bool => ! $record->is_recurring_occurrence)
                    ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::ObligationsDelete->value) ?? false),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::ObligationsDelete->value) ?? false),
                ]),
            ])
            ->emptyStateHeading('Nenhuma obrigação consolidada')
            ->emptyStateDescription('Aprove sugestões na aba "Obrigações Sugeridas" ou cadastre manualmente.');
    }

    protected function makeHistoryAction(): Action
    {
        return Action::make('history')
            ->label('Histórico')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->tooltip('Histórico')
            ->iconButton()
            ->modalHeading('Histórico da Obrigação')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->authorize(fn (): bool => $this->canViewHistory())
            ->modalContent(fn (Obligation $record) => view('filament.obligations.history-timeline', [
                'obligation' => $record,
                'entries' => $record->historyEntries()
                    ->with('user')
                    ->latest('occurred_at')
                    ->latest('id')
                    ->limit(200)
                    ->get(),
            ]));
    }

    protected function makeCommentsAction(): Action
    {
        return Action::make('comments')
            ->label('Comentários internos')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('gray')
            ->tooltip('Comentários internos')
            ->iconButton()
            ->url(fn (Obligation $record): string => EmissionResource::getUrl('obligation-comments', [
                'record' => $record->emission_id,
                'obligation' => $record->id,
            ]))
            ->authorize(fn (): bool => $this->canViewComments());
    }

    protected function canCreateObligations(): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsCreate->value) ?? false;
    }

    protected function canExportObligations(): bool
    {
        return (auth()->user()?->can(AccessPermission::ObligationsView->value) ?? false)
            && (auth()->user()?->can(AccessPermission::ObligationsExport->value) ?? false);
    }

    protected function canEditObligations(): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsUpdate->value) ?? false;
    }

    protected function canViewHistory(): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsViewHistory->value) ?? false;
    }

    protected function canViewComments(): bool
    {
        return (auth()->user()?->can(AccessPermission::EmissionsView->value) ?? false)
            && (auth()->user()?->can(AccessPermission::ObligationsView->value) ?? false)
            && (auth()->user()?->can(AccessPermission::ObligationsViewComments->value) ?? false);
    }

    protected function makeSubmitForReviewAction(): Action
    {
        return Action::make('submit_for_review')
            ->label('Enviar para análise')
            ->icon('heroicon-o-eye')
            ->color('warning')
            ->modalWidth(Width::Large)
            ->modalHeading('Enviar obrigação para análise')
            ->modalDescription('As evidências já anexadas seguirão para revisão documental e a obrigação passará para o status "Em análise".')
            ->modalSubmitActionLabel('Enviar para análise')
            ->form([
                Textarea::make('note')
                    ->label('Observação')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->placeholder('Descreva o contexto do envio para análise.'),
            ])
            ->visible(fn (Obligation $record): bool => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_SUBMIT_FOR_REVIEW))
            ->authorize(fn (Obligation $record): bool => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_SUBMIT_FOR_REVIEW))
            ->action(function (Obligation $record, array $data): void {
                $this->workflow()->submitForReview($record, auth()->user(), $data['note'] ?? null);
            })
            ->successNotificationTitle('Obrigação enviada para análise.');
    }

    protected function makeCompleteAction(): Action
    {
        return Action::make('complete_obligation')
            ->label(fn (Obligation $record): string => $record->is_recurring_occurrence ? 'Concluir competência' : 'Concluir obrigação')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->modalWidth(Width::Large)
            ->modalHeading(fn (Obligation $record): string => $record->is_recurring_occurrence ? 'Concluir competência '.$record->competence_label : 'Concluir obrigação')
            ->modalDescription('Esta ação conclui somente o item operacional exibido. Em recorrências, as demais competências permanecem independentes. Apenas evidência aprovada conta como comprovação válida; sem uma aprovada, a conclusão exige justificativa e confirmação explícita.')
            ->modalSubmitActionLabel('Concluir')
            ->form(fn (Obligation $record): array => $this->completeActionForm($record))
            ->visible(fn (Obligation $record): bool => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_COMPLETE))
            ->authorize(fn (Obligation $record): bool => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_COMPLETE))
            ->action(function (Obligation $record, array $data): void {
                $this->workflow()->complete(
                    $record,
                    auth()->user(),
                    $data['completion_notes'] ?? null,
                    (bool) ($data['confirm_without_evidence'] ?? false),
                );
            })
            ->successNotificationTitle('Obrigação concluída com sucesso.');
    }

    protected function makeMarkNotApplicableAction(): Action
    {
        return Action::make('mark_not_applicable')
            ->label('Marcar como não aplicável')
            ->icon('heroicon-o-no-symbol')
            ->color('gray')
            ->modalWidth(Width::Large)
            ->modalHeading('Marcar obrigação como não aplicável')
            ->modalDescription('Isto encerrará a obrigação sem exigir cumprimento, mantendo o motivo registrado para auditoria.')
            ->modalSubmitActionLabel('Marcar como não aplicável')
            ->form(fn (Obligation $record): array => [
                Select::make('scope')
                    ->label('Escopo')
                    ->options(fn (): array => array_filter([
                        'occurrence' => 'Somente esta competência',
                        'series' => $this->canEditObligations() ? 'Esta competência e encerrar toda a série' : null,
                    ]))
                    ->default('occurrence')
                    ->required()
                    ->visible($record->is_recurring_occurrence),
                Textarea::make('reason')
                    ->label('Motivo')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->placeholder('Explique por que esta obrigação não se aplica.'),
            ])
            ->visible(fn (Obligation $record): bool => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_MARK_NOT_APPLICABLE))
            ->authorize(fn (Obligation $record): bool => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_MARK_NOT_APPLICABLE))
            ->action(function (Obligation $record, array $data): void {
                DB::transaction(function () use ($record, $data): void {
                    $this->workflow()->markNotApplicable($record, auth()->user(), $data['reason'] ?? null);

                    if (($data['scope'] ?? 'occurrence') === 'series' && $record->series !== null) {
                        $this->seriesService()->close($record->series, auth()->user(), $data['reason'] ?? null);
                    }
                });
            })
            ->successNotificationTitle('Obrigação marcada como não aplicável.');
    }

    protected function makeReopenAction(): Action
    {
        return Action::make('reopen_obligation')
            ->label('Reabrir obrigação')
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->modalWidth(Width::Large)
            ->modalHeading('Reabrir obrigação')
            ->modalDescription('A obrigação voltará para acompanhamento operacional. O status será recalculado pela data de vencimento atual: vencida, a vencer ou em dia quando não houver vencimento definido.')
            ->modalSubmitActionLabel('Reabrir obrigação')
            ->form([
                Textarea::make('reason')
                    ->label('Motivo da reabertura')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->placeholder('Explique o motivo da reabertura.'),
            ])
            ->visible(fn (Obligation $record): bool => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_REOPEN))
            ->authorize(fn (Obligation $record): bool => $this->canRunWorkflowAction($record, ObligationWorkflowService::TRANSITION_REOPEN))
            ->action(function (Obligation $record, array $data): void {
                $this->workflow()->reopen($record, auth()->user(), $data['reason'] ?? null);
            })
            ->successNotificationTitle('Obrigação reaberta com sucesso.');
    }

    /**
     * @return array<int, Component>
     */
    protected function completeActionForm(Obligation $record): array
    {
        $totalEvidenceCount = $record->evidences()->count();
        $approvedEvidenceCount = $record->evidences()->approved()->count();
        $hasApprovedEvidence = $approvedEvidenceCount > 0;
        $withoutEvidenceCheckbox = Checkbox::make('confirm_without_evidence')
            ->label('Confirmo que esta conclusão seguirá sem evidência aprovada.')
            ->helperText('Use esta exceção apenas quando o cumprimento não gerar comprovação formal válida e a justificativa operacional registrada for suficiente.')
            ->visible(! $hasApprovedEvidence);

        if (! $hasApprovedEvidence) {
            $withoutEvidenceCheckbox->accepted()->required();
        }

        return [
            Placeholder::make('evidence_summary')
                ->label('Evidências anexadas')
                ->content(match (true) {
                    $approvedEvidenceCount > 0 => sprintf(
                        '%d evidência(s) aprovada(s) de %d anexada(s) à obrigação.',
                        $approvedEvidenceCount,
                        $totalEvidenceCount,
                    ),
                    $totalEvidenceCount > 0 => sprintf(
                        '%d evidência(s) anexada(s), mas nenhuma aprovada até o momento. A conclusão sem aprovada é tratada como exceção.',
                        $totalEvidenceCount,
                    ),
                    default => 'Nenhuma evidência anexada até o momento. A conclusão sem aprovada é tratada como exceção.',
                }),
            Textarea::make('completion_notes')
                ->label('Justificativa de conclusão')
                ->rows(4)
                ->required()
                ->maxLength(2000)
                ->placeholder('Descreva como a obrigação foi cumprida.'),
            $withoutEvidenceCheckbox,
        ];
    }

    protected function canRunWorkflowAction(Obligation $record, string $transition): bool
    {
        return $this->workflow()->canRunTransition(auth()->user(), $record, $transition);
    }

    protected function workflow(): ObligationWorkflowService
    {
        return app(ObligationWorkflowService::class);
    }

    protected function seriesService(): ObligationSeriesService
    {
        return app(ObligationSeriesService::class);
    }

    protected function sourceLabel(Obligation $obligation): string
    {
        return match (true) {
            $obligation->obligation_series_id !== null => 'Série recorrente',
            $obligation->extracted_obligation_id !== null => 'Gerada pelo Termo',
            default => 'Manual',
        };
    }

    protected function obligationsTableDescription(): string
    {
        $description = 'Acompanhe status, vencimento, prioridade, responsável e situação documental desta emissão.';

        if (! $this->canCreateObligations() && ! $this->canEditObligations() && ! $this->canExportObligations() && ! $this->userHasWorkflowPermission()) {
            return $description.' Modo consulta: seu perfil pode visualizar o fluxo, mas não executar ações operacionais.';
        }

        return $description.' Apenas evidência aprovada conta como comprovação válida para o cumprimento.';
    }

    protected function workflowAvailabilityMessage(Obligation $record): string
    {
        if ($this->userCanRunAnyWorkflowTransition($record)) {
            return 'Use as ações no rodapé deste dossiê para avançar o fluxo desta obrigação.';
        }

        if (! $this->userHasWorkflowPermission()) {
            return 'Seu perfil está em modo consulta. Você pode acompanhar a obrigação, mas não executar ações de workflow.';
        }

        return match ($record->status) {
            'concluida', 'nao_aplicavel' => 'Fluxo encerrado. Apenas um perfil com permissão de reabertura pode retomar o acompanhamento.',
            'em_analise' => 'A obrigação aguarda revisão documental por um perfil autorizado.',
            default => 'Nenhuma ação adicional está disponível para o seu perfil nesta etapa.',
        };
    }

    protected function userCanRunAnyWorkflowTransition(Obligation $record): bool
    {
        foreach ([
            ObligationWorkflowService::TRANSITION_SUBMIT_FOR_REVIEW,
            ObligationWorkflowService::TRANSITION_COMPLETE,
            ObligationWorkflowService::TRANSITION_MARK_NOT_APPLICABLE,
            ObligationWorkflowService::TRANSITION_REOPEN,
        ] as $transition) {
            if ($this->canRunWorkflowAction($record, $transition)) {
                return true;
            }
        }

        return false;
    }

    protected function userHasWorkflowPermission(): bool
    {
        $user = auth()->user();

        return collect([
            AccessPermission::ObligationsSubmitForReview->value,
            AccessPermission::ObligationsComplete->value,
            AccessPermission::ObligationsMarkNotApplicable->value,
            AccessPermission::ObligationsReopen->value,
        ])->contains(fn (string $permission): bool => (bool) $user?->can($permission));
    }
}
