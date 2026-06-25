<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Filament\Exports\ObligationExporter;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use App\Services\Obligations\ObligationWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    public function table(Table $table): Table
    {
        $dashboardData = app(ObligationDashboardData::class);
        $canViewEvidence = (bool) (auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value) ?? false);

        $columns = [
            TextColumn::make('title')
                ->label('Título')
                ->searchable()
                ->wrap(),
            TextColumn::make('obligation_category')
                ->label('Categoria')
                ->badge()
                ->toggleable(),
            TextColumn::make('responsibleUser.name')
                ->label('Responsável')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('responsible_area')
                ->label('Área')
                ->placeholder('—')
                ->toggleable(),
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
            TextColumn::make('source')
                ->label('Origem')
                ->badge()
                ->state(fn (Obligation $record): string => $record->extracted_obligation_id !== null ? 'Gerada pelo Termo' : 'Manual')
                ->color(fn (string $state): string => $state === 'Manual' ? 'gray' : 'info')
                ->toggleable(),
        ];

        if ($canViewEvidence) {
            $columns[] = TextColumn::make('document_status')
                ->label('Status das Evidências')
                ->state(fn (Obligation $record): string => $dashboardData->documentStatusFor($record))
                ->badge()
                ->color(fn (Obligation $record): string => $dashboardData->documentStatusColorFor($record))
                ->wrap();
            $columns[] = TextColumn::make('evidences_count')
                ->label('Qtd. Evidências')
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
            SelectFilter::make('due_window')
                ->label('Vencimento')
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
                        'term' => $query->whereNotNull('extracted_obligation_id'),
                        'manual' => $query->whereNull('extracted_obligation_id'),
                        default => $query,
                    };
                }),
        ];

        if ($canViewEvidence) {
            $filters[] = SelectFilter::make('evidence_state')
                ->label('Status das evidências')
                ->options(ObligationDashboardData::EVIDENCE_FILTER_OPTIONS)
                ->query(fn (Builder $query, array $data): Builder => $dashboardData->applyEvidenceFilter($query, $data['value'] ?? null));
        }

        return $table
            ->recordTitleAttribute('title')
            ->description('Obrigações consolidadas desta emissão.')
            ->modifyQueryUsing(function (Builder $query) use ($canViewEvidence): Builder {
                $query->with('responsibleUser');

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
                    ->label('Exportar obrigações da emissão')
                    ->authorize(fn (): bool => $this->canExportObligations())
                    ->columnMapping(false)
                    ->exporter(ObligationExporter::class),
            ])
            ->actions([
                $this->makeCommentsAction(),
                $this->makeHistoryAction(),
                $this->makeSubmitForReviewAction(),
                $this->makeCompleteAction(),
                $this->makeMarkNotApplicableAction(),
                $this->makeReopenAction(),
                EditAction::make()
                    ->authorize(fn (): bool => $this->canEditObligations()),
                DeleteAction::make()
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
            ->label('Concluir obrigação')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->modalWidth(Width::Large)
            ->modalHeading('Concluir obrigação')
            ->modalSubmitActionLabel('Concluir obrigação')
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
            ->modalSubmitActionLabel('Marcar como não aplicável')
            ->form([
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
                $this->workflow()->markNotApplicable($record, auth()->user(), $data['reason'] ?? null);
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
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected function completeActionForm(Obligation $record): array
    {
        $totalEvidenceCount = $record->evidences()->count();
        $approvedEvidenceCount = $record->evidences()->approved()->count();
        $hasApprovedEvidence = $approvedEvidenceCount > 0;
        $withoutEvidenceCheckbox = Checkbox::make('confirm_without_evidence')
            ->label('Confirmo a conclusão sem evidência aprovada.')
            ->helperText('Use esta opção apenas quando o cumprimento não gerar comprovação formal válida ou quando a justificativa operacional for suficiente.')
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
                        '%d evidência(s) anexada(s), mas nenhuma aprovada até o momento.',
                        $totalEvidenceCount,
                    ),
                    default => 'Nenhuma evidência anexada até o momento.',
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
}
