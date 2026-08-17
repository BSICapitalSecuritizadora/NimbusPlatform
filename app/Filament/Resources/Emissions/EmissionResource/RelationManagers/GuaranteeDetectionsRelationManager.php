<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
use App\Enums\LegalDocumentType;
use App\Jobs\GenerateEmissionGuaranteesJob;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedGuarantee;
use App\Models\GuaranteeGenerationRun;
use App\Services\Guarantees\GuaranteeSuggestionReviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

/**
 * Espaço de revisão das garantias identificadas nos documentos (§5 do escopo).
 *
 * Nada aqui é garantia da emissão: são propostas de cadastro. A confirmação é o
 * único caminho para a garantia existir, e a rejeição exige motivo.
 */
class GuaranteeDetectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'extractedGuarantees';

    protected static ?string $title = 'Garantias Detectadas';

    protected static ?string $modelLabel = 'Garantia detectada';

    protected static ?string $pluralModelLabel = 'Garantias Detectadas';

    protected ?GuaranteeGenerationRun $generationRunCache = null;

    protected bool $generationRunResolved = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::GuaranteesReviewSuggestions->value) ?? false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Emission) {
            return null;
        }

        $pending = $ownerRecord->extractedGuarantees()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('type')
                ->label('Tipo de Garantia')
                ->options(GuaranteeType::groupedOptions())
                ->searchable(),
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            Select::make('requirement_basis')
                ->label('Forma do mínimo')
                ->options(GuaranteeRequirementBasis::options()),
            TextInput::make('requirement_percentage')
                ->label('Percentual mínimo')
                ->numeric()
                ->step('0.0001')
                ->helperText('120% = 1,2'),
            Select::make('requirement_base')
                ->label('Base de cálculo')
                ->options(GuaranteeRequirementBase::options()),
            Textarea::make('review_notes')->label('Observações da revisão')->rows(3)->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->poll($this->hasActiveGenerationRun() ? '4s' : null)
            ->description(fn (): string|Htmlable => $this->generationDescription())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['document', 'relatedGuarantee', 'reviewer']))
            ->columns([
                TextColumn::make('name')
                    ->label('Garantia identificada')
                    ->description(fn (ExtractedGuarantee $record): string => GuaranteeType::labelFor($record->type))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('event_type')
                    ->label('Evento')
                    ->badge()
                    ->formatStateUsing(fn (ExtractedGuarantee $record): string => $record->event_type?->label() ?? '—')
                    ->color(fn (ExtractedGuarantee $record): string => $record->event_type?->color() ?? 'gray'),
                TextColumn::make('document.title')
                    ->label('Documento')
                    ->description(fn (ExtractedGuarantee $record): string => $this->sourceLocation($record))
                    ->placeholder('Documento não vinculado')
                    ->wrap(),
                TextColumn::make('confidence_score')
                    ->label('Confiança')
                    ->badge()
                    ->formatStateUsing(fn (ExtractedGuarantee $record): string => $record->confidenceLevel()?->label() ?? '—')
                    ->color(fn (ExtractedGuarantee $record): string => $record->confidenceLevel()?->color() ?? 'gray'),
                TextColumn::make('has_conflict')
                    ->label('Conflito')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Revisão necessária' : 'Sem conflito')
                    ->color(fn (bool $state): string => $state ? 'danger' : 'gray')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ExtractedGuarantee $record): string => $record->status?->label() ?? '—')
                    ->color(fn (ExtractedGuarantee $record): string => $record->status?->color() ?? 'gray'),
                TextColumn::make('reviewer.name')
                    ->label('Revisado por')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(GuaranteeDetectionStatus::options()),
            ])
            ->headerActions([
                $this->makeGenerateAction(),
            ])
            ->actions([
                $this->makeReviewAction(),
                $this->makeApproveAction(),
                $this->makeRejectAction(),
                Action::make('open_document')
                    ->label('Ver no documento')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (ExtractedGuarantee $record): ?string => $record->document === null
                        ? null
                        : route('admin.documents.download', $record->document))
                    ->openUrlInNewTab()
                    ->visible(fn (ExtractedGuarantee $record): bool => $record->document !== null
                        && Route::has('admin.documents.download')),
            ])
            ->emptyStateHeading('Nenhuma garantia detectada')
            ->emptyStateDescription('Use "Identificar nos documentos" para analisar o Termo, os aditamentos e os instrumentos de garantia da operação.');
    }

    /**
     * Painel de revisão: mostra o que foi extraído, com que evidência, e o
     * trecho literal que sustenta a proposta.
     */
    protected function makeReviewAction(): Action
    {
        return Action::make('review')
            ->label('Revisar')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->modalHeading(fn (ExtractedGuarantee $record): string => $record->name)
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (ExtractedGuarantee $record): View => view(
                'filament.resources.emissions.relation-managers.guarantee-detection-review',
                ['candidate' => $record->load(['document', 'relatedGuarantee'])],
            ));
    }

    protected function makeApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Confirmar')
            ->icon('heroicon-o-check')
            ->color('success')
            ->modalHeading('Confirmar garantia detectada')
            ->modalDescription('A garantia passa a integrar oficialmente a emissão, com a origem documental preservada. Ajuste os campos abaixo antes de confirmar, se necessário.')
            ->modalSubmitActionLabel('Confirmar garantia')
            ->fillForm(fn (ExtractedGuarantee $record): array => [
                'name' => $record->name,
                'requirement_percentage' => $record->requirement_percentage,
                'review_notes' => null,
            ])
            ->form([
                TextInput::make('name')->label('Nome da garantia')->required()->maxLength(255),
                TextInput::make('requirement_percentage')
                    ->label('Percentual mínimo')
                    ->numeric()
                    ->step('0.0001')
                    ->helperText('Deixe vazio para manter o extraído. 120% = 1,2.'),
                Textarea::make('review_notes')
                    ->label('Observação da revisão')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (ExtractedGuarantee $record): bool => $this->canReview($record, GuaranteeSuggestionReviewService::TRANSITION_APPROVE))
            ->authorize(fn (ExtractedGuarantee $record): bool => $this->canReview($record, GuaranteeSuggestionReviewService::TRANSITION_APPROVE))
            ->action(function (ExtractedGuarantee $record, array $data): void {
                $overrides = array_filter(
                    [
                        'name' => $data['name'] ?? null,
                        'requirement_percentage' => $data['requirement_percentage'] ?? null,
                    ],
                    static fn (mixed $value): bool => filled($value),
                );

                app(GuaranteeSuggestionReviewService::class)->approve(
                    suggestion: $record,
                    actor: auth()->user(),
                    overrides: $overrides,
                    reviewNotes: $data['review_notes'] ?? null,
                );

                Notification::make()
                    ->title('Garantia confirmada e incorporada à emissão.')
                    ->success()
                    ->send();
            });
    }

    protected function makeRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Rejeitar')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->modalHeading('Rejeitar garantia detectada')
            ->modalDescription('A rejeição exige motivo e encerra a análise desta candidata sem criar garantia.')
            ->modalSubmitActionLabel('Rejeitar')
            ->form([
                Textarea::make('review_notes')
                    ->label('Motivo da rejeição')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(fn (ExtractedGuarantee $record): bool => $this->canReview($record, GuaranteeSuggestionReviewService::TRANSITION_REJECT))
            ->authorize(fn (ExtractedGuarantee $record): bool => $this->canReview($record, GuaranteeSuggestionReviewService::TRANSITION_REJECT))
            ->action(function (ExtractedGuarantee $record, array $data): void {
                app(GuaranteeSuggestionReviewService::class)->reject($record, auth()->user(), $data['review_notes'] ?? null);

                Notification::make()->title('Garantia detectada rejeitada.')->success()->send();
            });
    }

    /**
     * Dispara a análise dos documentos jurídicos da operação.
     *
     * Só documentos classificados juridicamente entram: sem a classificação não
     * há como ordenar a cadeia documental nem decidir o que prevalece (§35).
     */
    protected function makeGenerateAction(): Action
    {
        return Action::make('generate_guarantees')
            ->label('Identificar nos documentos')
            ->icon('heroicon-o-sparkles')
            ->color('warning')
            ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::GuaranteesGenerate->value) ?? false)
            ->disabled(fn (): bool => $this->hasActiveGenerationRun())
            ->modalHeading('Identificar garantias nos documentos')
            ->modalDescription('A análise lê o documento escolhido e propõe as garantias nele previstas. Nada é cadastrado automaticamente: as propostas ficam pendentes de revisão.')
            ->modalSubmitActionLabel('Iniciar análise')
            ->form([
                Select::make('document_id')
                    ->label('Documento')
                    ->options(fn (): array => $this->legalDocumentOptions())
                    ->required()
                    ->helperText('Classifique os documentos da operação (Termo, aditamentos, instrumentos de garantia) para que apareçam aqui.'),
            ])
            ->action(function (array $data): void {
                if ($this->hasActiveGenerationRun()) {
                    Notification::make()
                        ->title('Análise já em andamento')
                        ->body('Aguarde a conclusão antes de iniciar outra.')
                        ->warning()
                        ->send();

                    return;
                }

                $emission = $this->getOwnerRecord();

                $run = GuaranteeGenerationRun::create([
                    'emission_id' => $emission->id,
                    'document_id' => $data['document_id'],
                    'user_id' => auth()->id(),
                    'status' => GuaranteeGenerationRun::STATUS_PENDING,
                    'current_step' => 'queued',
                    'message' => 'Preparando leitura do documento...',
                ]);

                GenerateEmissionGuaranteesJob::dispatch($emission->id, (int) $data['document_id'], $run->id);

                Notification::make()
                    ->title('Análise iniciada')
                    ->body('Acompanhe o progresso nesta aba. As garantias detectadas aparecerão ao concluir.')
                    ->info()
                    ->send();
            });
    }

    /**
     * @return array<int, string>
     */
    protected function legalDocumentOptions(): array
    {
        return $this->getOwnerRecord()
            ->legalDocuments()
            ->get()
            ->mapWithKeys(function (Document $document): array {
                $type = LegalDocumentType::tryFrom((string) $document->pivot->legal_document_type);
                $date = $document->pivot->document_date;

                $label = $document->title;

                if ($type !== null) {
                    $label = "{$type->label()} — {$label}";
                }

                if (filled($date)) {
                    $label .= ' ('.Carbon::parse($date)->format('d/m/Y').')';
                }

                return [$document->id => $label];
            })
            ->all();
    }

    protected function sourceLocation(ExtractedGuarantee $record): string
    {
        $parts = array_filter([
            filled($record->source_clause) ? "Cláusula {$record->source_clause}" : null,
            $record->source_page !== null ? "Página {$record->source_page}" : null,
        ]);

        return $parts === [] ? 'Localização não informada' : implode(' · ', $parts);
    }

    protected function canReview(ExtractedGuarantee $record, string $transition): bool
    {
        return app(GuaranteeSuggestionReviewService::class)
            ->canRunTransition(auth()->user(), $record, $transition);
    }

    protected function latestGenerationRun(): ?GuaranteeGenerationRun
    {
        if ($this->generationRunResolved) {
            return $this->generationRunCache;
        }

        $this->generationRunResolved = true;

        return $this->generationRunCache = $this->getOwnerRecord()
            ->latestGuaranteeGenerationRun()
            ->first();
    }

    protected function hasActiveGenerationRun(): bool
    {
        return $this->latestGenerationRun()?->isActive() ?? false;
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
            ? view('filament.resources.emissions.relation-managers.guarantee-generation-progress', ['run' => $run])->render()
            : '';

        return new HtmlString(
            $banner
            .'<span class="block">Garantias identificadas nos documentos da operação. Nenhuma delas integra a emissão até ser confirmada.</span>'
            .'<span class="mt-1 block text-sm text-gray-600">Confirme para incorporar com a origem documental preservada, ou rejeite informando o motivo.</span>'
        );
    }
}
