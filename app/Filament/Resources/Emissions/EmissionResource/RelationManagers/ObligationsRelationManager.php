<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Models\Obligation;
use App\Services\Obligations\ObligationWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ObligationsRelationManager extends RelationManager
{
    protected static string $relationship = 'obligations';

    protected static ?string $title = 'Obrigações';

    protected static ?string $modelLabel = 'Obrigação';

    protected static ?string $pluralModelLabel = 'Obrigações';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('obligations.view') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(ObligationFormFields::make('obligation'))->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->description('Obrigações consolidadas desta emissão.')
            ->columns([
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
            ])
            ->defaultSort('due_date', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Obligation::STATUS_OPTIONS),
                SelectFilter::make('priority')
                    ->label('Prioridade')
                    ->options(Obligation::PRIORITY_OPTIONS),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Cadastrar obrigação')
                    ->authorize(fn (): bool => $this->canManage()),
            ])
            ->actions([
                $this->makeHistoryAction(),
                $this->makeSubmitForReviewAction(),
                $this->makeCompleteAction(),
                $this->makeMarkNotApplicableAction(),
                $this->makeReopenAction(),
                EditAction::make()
                    ->authorize(fn (): bool => $this->canManage()),
                DeleteAction::make()
                    ->authorize(fn (): bool => auth()->user()?->can('obligations.delete') ?? false),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('obligations.delete') ?? false),
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
            ->authorize(fn (): bool => auth()->user()?->can('obligations.view') ?? false)
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

    protected function canManage(): bool
    {
        return auth()->user()?->can('obligations.update') ?? false;
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
        $evidenceCount = $record->evidences()->count();
        $hasEvidence = $evidenceCount > 0;
        $withoutEvidenceCheckbox = Checkbox::make('confirm_without_evidence')
            ->label('Confirmo a conclusão sem evidência anexada.')
            ->helperText('Use esta opção apenas quando o cumprimento não gerar comprovação formal ou quando a justificativa operacional for suficiente.')
            ->visible(! $hasEvidence);

        if (! $hasEvidence) {
            $withoutEvidenceCheckbox->accepted()->required();
        }

        return [
            Placeholder::make('evidence_summary')
                ->label('Evidências anexadas')
                ->content($hasEvidence
                    ? sprintf('%d evidência(s) vinculada(s) à obrigação.', $evidenceCount)
                    : 'Nenhuma evidência anexada até o momento.'),
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
        if (! $this->workflow()->canUserRunWorkflow(auth()->user())) {
            return false;
        }

        return match ($transition) {
            ObligationWorkflowService::TRANSITION_SUBMIT_FOR_REVIEW => $this->workflow()->canSubmitForReview($record),
            ObligationWorkflowService::TRANSITION_COMPLETE => $this->workflow()->canComplete($record),
            ObligationWorkflowService::TRANSITION_MARK_NOT_APPLICABLE => $this->workflow()->canMarkNotApplicable($record),
            ObligationWorkflowService::TRANSITION_REOPEN => $this->workflow()->canReopen($record),
            default => false,
        };
    }

    protected function workflow(): ObligationWorkflowService
    {
        return app(ObligationWorkflowService::class);
    }
}
