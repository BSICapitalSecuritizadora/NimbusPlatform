<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Jobs\GenerateEmissionObligationsJob;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\ObligationGenerationRun;
use App\Services\Obligations\ObligationSuggestionReviewService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class ObligationSuggestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'extractedObligations';

    protected static ?string $title = 'Obrigações Sugeridas';

    protected static ?string $modelLabel = 'Sugestão';

    protected static ?string $pluralModelLabel = 'Obrigações Sugeridas';

    protected ?ObligationGenerationRun $generationRunCache = null;

    protected bool $generationRunResolved = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsView->value) ?? false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Emission) {
            return null;
        }

        $pending = $ownerRecord->extractedObligations()
            ->where('status', ExtractedObligation::STATUS_SUGGESTED)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(ObligationFormFields::make('suggestion'))->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->poll($this->shouldPollGeneration() ? '4s' : null)
            ->description(fn (): string|Htmlable => $this->generationDescription())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['responsibleUser', 'reviewer', 'obligation']))
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ExtractedObligation::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        ExtractedObligation::STATUS_APPROVED => 'success',
                        ExtractedObligation::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('obligation.title')
                    ->label('Obrigação criada')
                    ->placeholder('Ainda não criada')
                    ->limit(50)
                    ->wrap()
                    ->url(fn (ExtractedObligation $record): ?string => $record->obligation?->id
                        ? EmissionResource::getUrl('edit', ['record' => $record->emission_id])
                        : null)
                    ->openUrlInNewTab()
                    ->toggleable(),
                TextColumn::make('review_notes')
                    ->label('Motivo / observação da revisão')
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('reviewer.name')
                    ->label('Revisado por')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reviewed_at')
                    ->label('Revisado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ExtractedObligation::PRIORITY_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('responsibleUser.name')
                    ->label('Responsável')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('due_rule')
                    ->label('Prazo')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('obligation_category')
                    ->label('Categoria')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confidence_score')
                    ->label('Confiança')
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : round($state * 100).'%')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_excerpt')
                    ->label('Trecho do Termo')
                    ->placeholder('—')
                    ->limit(70)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('confidence_score', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ExtractedObligation::STATUS_OPTIONS),
            ])
            ->headerActions([
                $this->makeGenerateAction(),
            ])
            ->actions([
                $this->makeApproveAction(),
                $this->makeRejectAction(),
                Action::make('view_obligation')
                    ->label('Abrir obrigação criada')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (ExtractedObligation $record): string => EmissionResource::getUrl('edit', ['record' => $record->emission_id]))
                    ->openUrlInNewTab()
                    ->visible(fn (ExtractedObligation $record): bool => filled($record->obligation?->id))
                    ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::ObligationsView->value) ?? false),
                EditAction::make()
                    ->label('Editar')
                    ->authorize(fn (): bool => $this->canManage()),
                DeleteAction::make()
                    ->authorize(fn (): bool => $this->canManage()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => $this->canManage()),
                ]),
            ])
            ->emptyStateHeading('Nenhuma obrigação sugerida')
            ->emptyStateDescription('Use "Gerar obrigações do Termo" para extrair sugestões do Termo de Securitização. Sugestões aprovadas ou rejeitadas não possuem reabertura nesta etapa.');
    }

    protected function makeGenerateAction(): Action
    {
        return Action::make('generate_obligations')
            ->label('Gerar obrigações do Termo')
            ->icon('heroicon-o-sparkles')
            ->color('warning')
            ->authorize(fn (): bool => $this->canGenerateObligations())
            ->disabled(fn (): bool => $this->hasActiveGenerationRun())
            ->requiresConfirmation()
            ->modalHeading('Gerar obrigações do Termo de Securitização')
            ->modalDescription('A IA analisará o Termo de Securitização e gerará obrigações sugeridas. O processo pode levar alguns minutos. As sugestões pendentes anteriores serão substituídas.')
            ->modalSubmitActionLabel('Iniciar geração')
            ->action(function (): void {
                if ($this->hasActiveGenerationRun()) {
                    Notification::make()
                        ->title('Geração já em andamento')
                        ->body('Aguarde a conclusão da geração atual antes de iniciar uma nova.')
                        ->warning()
                        ->send();

                    return;
                }

                $document = $this->findSecuritizationTerm();

                if ($document === null) {
                    Notification::make()
                        ->title('Termo de Securitização não encontrado')
                        ->body('Adicione o documento na seção "Documentos da Operação" com o título exato "Termo de Securitização".')
                        ->warning()
                        ->send();

                    return;
                }

                $run = ObligationGenerationRun::create([
                    'emission_id' => $this->getOwnerRecord()->id,
                    'document_id' => $document->id,
                    'user_id' => auth()->id(),
                    'status' => ObligationGenerationRun::STATUS_PENDING,
                    'current_step' => 'queued',
                    'message' => 'Preparando leitura do Termo...',
                ]);

                GenerateEmissionObligationsJob::dispatch($this->getOwnerRecord()->id, $document->id, $run->id);

                Notification::make()
                    ->title('Geração de obrigações iniciada')
                    ->body('Acompanhe o progresso nesta aba. As sugestões aparecerão automaticamente ao concluir.')
                    ->info()
                    ->send();
            });
    }

    protected function latestGenerationRun(): ?ObligationGenerationRun
    {
        if ($this->generationRunResolved) {
            return $this->generationRunCache;
        }

        $this->generationRunResolved = true;

        return $this->generationRunCache = $this->getOwnerRecord()
            ->latestObligationGenerationRun()
            ->first();
    }

    protected function hasActiveGenerationRun(): bool
    {
        return $this->latestGenerationRun()?->isActive() ?? false;
    }

    protected function shouldPollGeneration(): bool
    {
        return $this->hasActiveGenerationRun();
    }

    protected function generationDescription(): string|Htmlable
    {
        $run = $this->latestGenerationRun();

        $isDisplayable = $run !== null && (
            $run->isActive()
            || $run->hasFailed()
            || ($run->isCompleted() && $run->finished_at?->gt(now()->subMinutes(10)))
        );

        $banner = $isDisplayable
            ? view('filament.obligations.generation-progress', ['run' => $run])->render()
            : '';

        return new HtmlString(
            $banner.'<span class="block">Revise as obrigações sugeridas pela IA a partir do Termo de Securitização e tome uma decisão formal de aprovação ou rejeição.</span><span class="mt-1 block text-sm text-gray-600">Sugestões aprovadas criam uma obrigação na emissão; sugestões rejeitadas encerram a análise e não possuem reabertura nesta etapa.</span>'.$this->readOnlySuggestionHint()
        );
    }

    protected function makeApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprovar sugestão')
            ->icon('heroicon-o-check')
            ->color('success')
            ->modalHeading('Aprovar sugestão')
            ->modalDescription('A sugestão será aprovada e uma obrigação será criada nesta emissão.')
            ->modalSubmitActionLabel('Aprovar sugestão')
            ->form([
                Textarea::make('review_notes')
                    ->label('Observação da revisão')
                    ->rows(4)
                    ->maxLength(2000)
                    ->placeholder('Observação opcional sobre a aprovação da sugestão.'),
            ])
            ->visible(fn (ExtractedObligation $record): bool => $this->canReviewSuggestion($record, ObligationSuggestionReviewService::TRANSITION_APPROVE))
            ->authorize(fn (ExtractedObligation $record): bool => $this->canReviewSuggestion($record, ObligationSuggestionReviewService::TRANSITION_APPROVE))
            ->action(function (ExtractedObligation $record, array $data): void {
                $this->reviewService()->approve($record, auth()->user(), $data['review_notes'] ?? null);
            })
            ->successNotificationTitle('Sugestão aprovada e obrigação criada com sucesso.');
    }

    protected function makeRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Rejeitar sugestão')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->modalHeading('Rejeitar sugestão')
            ->modalDescription('A rejeição exige motivo, encerra a sugestão sem criar obrigação e não há fluxo de reabertura nesta etapa.')
            ->modalSubmitActionLabel('Rejeitar sugestão')
            ->form([
                Textarea::make('review_notes')
                    ->label('Motivo da rejeição')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->placeholder('Informe por que a sugestão foi rejeitada.'),
            ])
            ->visible(fn (ExtractedObligation $record): bool => $this->canReviewSuggestion($record, ObligationSuggestionReviewService::TRANSITION_REJECT))
            ->authorize(fn (ExtractedObligation $record): bool => $this->canReviewSuggestion($record, ObligationSuggestionReviewService::TRANSITION_REJECT))
            ->action(function (ExtractedObligation $record, array $data): void {
                $this->reviewService()->reject($record, auth()->user(), $data['review_notes'] ?? null);
            })
            ->successNotificationTitle('Sugestão rejeitada com sucesso.');
    }

    protected function findSecuritizationTerm(): ?\App\Models\Document
    {
        return $this->getOwnerRecord()->documents()
            ->where('category', 'documentos_operacao')
            ->whereRaw('TRIM(title) = ?', ['Termo de Securitização'])
            ->first();
    }

    protected function canManage(): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsCreate->value) ?? false;
    }

    protected function canGenerateObligations(): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsGenerate->value) ?? false;
    }

    protected function canReviewSuggestion(ExtractedObligation $record, string $transition): bool
    {
        return $this->reviewService()->canRunTransition(auth()->user(), $record, $transition);
    }

    protected function reviewService(): ObligationSuggestionReviewService
    {
        return app(ObligationSuggestionReviewService::class);
    }

    protected function readOnlySuggestionHint(): string
    {
        if (
            $this->canGenerateObligations()
            || (auth()->user()?->can(AccessPermission::ObligationsApproveSuggestion->value) ?? false)
            || (auth()->user()?->can(AccessPermission::ObligationsRejectSuggestion->value) ?? false)
            || $this->canManage()
        ) {
            return '';
        }

        return '<span class="mt-1 block text-sm text-gray-600">Modo consulta: seu perfil pode acompanhar as sugestões, mas não gerar, aprovar ou rejeitar registros.</span>';
    }
}
