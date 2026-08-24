<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Filament\Resources\Emissions\Schemas\ObligationSeriesFormFields;
use App\Jobs\GenerateEmissionObligationsJob;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\ObligationGenerationRun;
use App\Services\Obligations\ObligationSuggestionReviewService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['responsibleUser', 'reviewer', 'obligation', 'obligationSeries']))
            ->columns([
                TextColumn::make('title')
                    ->label('Título da obrigação')
                    ->description(fn (ExtractedObligation $record): ?string => filled($record->obligation_category) ? $record->obligation_category : ($record->obligation_type ?? null))
                    ->searchable()
                    ->weight('bold')
                    ->tooltip(fn (ExtractedObligation $record): string => $record->title)
                    ->wrap(),
                TextColumn::make('description')
                    ->label('Fundamentação')
                    ->state(fn (ExtractedObligation $record): string => filled($record->description) ? $record->description : ($record->source_excerpt ?? '—'))
                    ->description(fn (ExtractedObligation $record): ?string => filled($record->source_clause) ? "Cláusula {$record->source_clause}".($record->source_page ? " · pág. {$record->source_page}" : '') : null)
                    ->tooltip(fn (ExtractedObligation $record): ?string => $record->source_excerpt ?? $record->description)
                    ->limit(100)
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        $q->where('description', 'like', "%{$search}%")
                            ->orWhere('source_excerpt', 'like', "%{$search}%");
                    })),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ExtractedObligation::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        ExtractedObligation::STATUS_APPROVED => 'success',
                        ExtractedObligation::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('responsibleUser.name')
                    ->label('Responsável')
                    ->placeholder('—')
                    ->description(fn (ExtractedObligation $record): ?string => $record->responsible_area ?? $record->responsible_party)
                    ->tooltip(fn (ExtractedObligation $record): ?string => $record->responsibleUser?->name),
                TextColumn::make('due_rule')
                    ->label('Prazo / Recorrência')
                    ->state(fn (ExtractedObligation $record): string => filled($record->due_rule) ? $record->due_rule : ($record->due_date ? $record->due_date->format('d/m/Y') : ($record->recurrence ?? '—')))
                    ->description(fn (ExtractedObligation $record): ?string => filled($record->due_rule) && filled($record->recurrence) ? $record->recurrence : ($record->due_date ? 'Vencimento: '.$record->due_date->format('d/m/Y') : null))
                    ->placeholder('—')
                    ->limit(35),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ExtractedObligation::PRIORITY_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confidence_score')
                    ->label('Confiança')
                    ->badge()
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : round($state * 100).'%')
                    ->color(fn (?float $state): string => match (true) {
                        $state >= 0.85 => 'success',
                        $state >= 0.65 => 'warning',
                        $state !== null => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (ExtractedObligation $record): ?string => $record->confidencePercent() ? "Nível de confiança: {$record->confidencePercent()}" : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('consolidated_target')
                    ->label('Destino criado')
                    ->state(fn (ExtractedObligation $record): ?string => $record->obligation?->operational_title
                        ?? ($record->obligationSeries !== null ? 'Série: '.$record->obligationSeries->title : null))
                    ->placeholder('Ainda não criada')
                    ->limit(50)
                    ->wrap()
                    ->url(fn (ExtractedObligation $record): ?string => $record->obligation?->id || $record->obligationSeries?->id
                        ? EmissionResource::getUrl('edit', ['record' => $record->emission_id])
                        : null)
                    ->openUrlInNewTab()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('review_notes')
                    ->label('Motivo / observação da revisão')
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewer.name')
                    ->label('Revisado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewed_at')
                    ->label('Revisado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('obligation_category')
                    ->label('Categoria')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_excerpt')
                    ->label('Trecho do Termo')
                    ->placeholder('—')
                    ->limit(70)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('confidence_score', 'desc')
            ->searchPlaceholder('Buscar por título ou fundamentação...')
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(ExtractedObligation::STATUS_OPTIONS),
                SelectFilter::make('priority')
                    ->label('Prioridade')
                    ->options(ExtractedObligation::PRIORITY_OPTIONS),
                SelectFilter::make('responsible_user_id')
                    ->label('Responsável')
                    ->relationship('responsibleUser', 'name'),
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
                    ->visible(fn (ExtractedObligation $record): bool => filled($record->obligation?->id) || filled($record->obligationSeries?->id))
                    ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::ObligationsView->value) ?? false),
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->authorize(fn (): bool => $this->canManage()),
                    DeleteAction::make()
                        ->authorize(fn (): bool => $this->canManage()),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->tooltip('Mais ações'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => $this->canManage()),
                ]),
            ])
            ->emptyStateHeading('Nenhuma obrigação sugerida')
            ->emptyStateDescription('Use "Gerar obrigações do Termo" para extrair sugestões do Termo de Securitização. Sugestões aprovadas ou rejeitadas não possuem reabertura nesta etapa.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
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

        $emission = $this->getOwnerRecord();
        $total = $emission->extractedObligations()->count();
        $approved = $emission->extractedObligations()->where('status', ExtractedObligation::STATUS_APPROVED)->count();
        $pending = $emission->extractedObligations()->where('status', ExtractedObligation::STATUS_SUGGESTED)->count();
        $rejected = $emission->extractedObligations()->where('status', ExtractedObligation::STATUS_REJECTED)->count();

        $summaryHtml = '';
        if ($total > 0) {
            $summaryHtml = sprintf(
                '<div class="mt-2.5 flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-md bg-[#0c232e] px-2.5 py-1 text-slate-300 border border-[#1d4554]/50 font-medium">%d obrigação(ões) identificada(s)</span>
                    <span class="rounded-md bg-emerald-950/60 px-2.5 py-1 text-emerald-300 border border-emerald-500/30 font-medium">%d aprovada(s)</span>
                    %s
                    %s
                </div>',
                $total,
                $approved,
                $pending > 0
                    ? sprintf('<span class="rounded-md bg-amber-950/60 px-2.5 py-1 text-amber-300 border border-amber-500/30 font-medium">%d pendente(s) de revisão</span>', $pending)
                    : '<span class="rounded-md bg-slate-800/80 px-2.5 py-1 text-slate-400 border border-slate-700">Todas revisadas</span>',
                $rejected > 0
                    ? sprintf('<span class="rounded-md bg-rose-950/60 px-2.5 py-1 text-rose-300 border border-rose-500/30 font-medium">%d rejeitada(s)</span>', $rejected)
                    : ''
            );
        }

        return new HtmlString(
            $banner
            .'<span class="block text-slate-300">Revise as obrigações sugeridas pela IA a partir do Termo de Securitização e tome uma decisão formal de aprovação ou rejeição.</span>'
            .'<span class="mt-0.5 block text-xs text-slate-400">Sugestões aprovadas criam uma obrigação na emissão; sugestões rejeitadas encerram a análise e não possuem reabertura nesta etapa.</span>'
            .$this->readOnlySuggestionHint()
            .$summaryHtml
        );
    }

    protected function makeApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprovar sugestão')
            ->icon('heroicon-o-check')
            ->color('success')
            ->modalHeading('Aprovar sugestão')
            ->modalDescription(fn (ExtractedObligation $record): string => $this->isRecurringSuggestion($record)
                ? 'Confirme a regra executável. A aprovação criará uma série e materializará competências independentes dentro da janela configurada.'
                : 'A sugestão será aprovada e uma obrigação única será criada nesta emissão.')
            ->modalSubmitActionLabel('Aprovar sugestão')
            ->form(fn (ExtractedObligation $record): array => [
                Textarea::make('review_notes')
                    ->label('Observação da revisão')
                    ->rows(4)
                    ->maxLength(2000)
                    ->placeholder('Observação opcional sobre a aprovação da sugestão.')
                    ->columnSpanFull(),
                Toggle::make('activate_series')
                    ->label('Confirmar e ativar a regra recorrente agora')
                    ->helperText('Desmarcado: cria a série aguardando configuração e preserva a obrigação sugerida como ocorrência candidata, sem inventar competência ou datas futuras.')
                    ->default(false)
                    ->live()
                    ->visible($this->isRecurringSuggestion($record))
                    ->columnSpanFull(),
                ...($this->isRecurringSuggestion($record) ? [
                    Section::make('Regra executável confirmada')
                        ->description('Estes dados controlam a geração automática e ficam separados do texto jurídico extraído.')
                        ->schema(ObligationSeriesFormFields::configurationFields($record->emission?->maturity_date?->toDateString()))
                        ->visible(fn (Get $get): bool => (bool) $get('activate_series'))
                        ->columnSpanFull(),
                ] : []),
            ])
            ->fillForm(function (ExtractedObligation $record): array {
                $schedule = $record->schedule_suggestion ?? [];
                $suggestsBusinessDayOffset = ($schedule['unit'] ?? null) === 'business_days'
                    && in_array($schedule['direction'] ?? null, ['before', 'after'], true)
                    && filled($schedule['anchor_description'] ?? null);

                return [
                    'activate_series' => false,
                    'frequency' => ObligationFrequency::fromLegacyLabel($record->recurrence)?->value,
                    'starts_on' => now()->startOfMonth()->toDateString(),
                    'ends_on' => $record->emission?->maturity_date?->toDateString(),
                    'due_rule_type' => $suggestsBusinessDayOffset
                        ? ObligationDueRuleType::BusinessDaysRelativeToEvent->value
                        : null,
                    'due_offset_months' => 1,
                    'relative_offset_quantity' => $suggestsBusinessDayOffset ? ($schedule['quantity'] ?? null) : null,
                    'relative_offset_unit' => $suggestsBusinessDayOffset ? 'business_days' : null,
                    'relative_offset_direction' => $suggestsBusinessDayOffset ? ($schedule['direction'] ?? null) : null,
                    'anchor_description' => $suggestsBusinessDayOffset ? ($schedule['anchor_description'] ?? null) : null,
                    'initial_date_inclusion' => in_array($schedule['initial_date_inclusion'] ?? null, ['included', 'excluded'], true)
                        ? $schedule['initial_date_inclusion']
                        : null,
                    'calendar_code' => null,
                    'calendar_evidence_clause_reference' => $record->source_clause,
                    'calendar_evidence_page_reference' => $record->source_page,
                    'calendar_evidence_excerpt' => $record->source_excerpt,
                    'calendar_evidence_notes' => $schedule['business_day_definition'] ?? null,
                    'generation_horizon_days' => (int) config('obligations.recurrence.generation_horizon_days', 90),
                ];
            })
            ->visible(fn (ExtractedObligation $record): bool => $this->canReviewSuggestion($record, ObligationSuggestionReviewService::TRANSITION_APPROVE))
            ->authorize(fn (ExtractedObligation $record): bool => $this->canReviewSuggestion($record, ObligationSuggestionReviewService::TRANSITION_APPROVE))
            ->action(function (ExtractedObligation $record, array $data): void {
                $seriesConfiguration = $this->isRecurringSuggestion($record) && ($data['activate_series'] ?? false)
                    ? Arr::except($data, ['review_notes', 'activate_series'])
                    : null;

                $this->reviewService()->approve(
                    $record,
                    auth()->user(),
                    $data['review_notes'] ?? null,
                    $seriesConfiguration,
                );
            })
            ->successNotificationTitle('Sugestão aprovada e destino operacional criado com sucesso.');
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

    protected function findSecuritizationTerm(): ?Document
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

    protected function isRecurringSuggestion(ExtractedObligation $suggestion): bool
    {
        return filled($suggestion->recurrence)
            && ObligationFrequency::fromLegacyLabel($suggestion->recurrence) !== ObligationFrequency::Once;
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
