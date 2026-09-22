<?php

declare(strict_types=1);

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceConfidence;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceValueOrigin;
use App\Domain\PuCalculator\Services\PuBaselineEvidenceExtractionService;
use App\Domain\PuCalculator\Services\PuBaselineEvidenceReviewService;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Enums\AccessPermission;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class PuBaselineEvidencesRelationManager extends RelationManager
{
    protected static string $relationship = 'puBaselineEvidences';

    protected static ?string $title = 'Evidências do baseline de PU';

    protected static ?string $modelLabel = 'Evidência do baseline';

    protected static ?string $pluralModelLabel = 'Evidências do baseline';

    private const FIELD_LABELS = [
        'document_type' => 'Tipo do documento',
        'evidenced_value' => 'Valor demonstrado',
        'reference' => 'Página, cláusula ou referência',
        'confidence' => 'Confiança da extração',
        'notes' => 'Observações',
    ];

    /**
     * Memo por requisição: o painel, as dicas dos campos e as ações leem a
     * mesma análise várias vezes a cada renderização. Propriedade privada não
     * é serializada pelo Livewire, então nasce vazia a cada requisição.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $extractionMemo = [];

    /** @var array<int, Document|null> */
    private array $documentMemo = [];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Emission
            && app(PuBaselineReadinessService::class)->supports($ownerRecord)
            && (auth()->user()?->can(AccessPermission::PuCurveView->value) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Documentos vinculados provam valores específicos. Criar a evidência não a aprova e não altera parâmetros de PU.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['document', 'createdBy', 'reviewedBy']))
            ->columns([
                TextColumn::make('evidence_type')
                    ->label('Requisito')
                    ->formatStateUsing(fn (PuBaselineEvidenceType $state): string => $state->label())
                    ->wrap()
                    ->weight('semibold'),
                TextColumn::make('evidenced_value')
                    ->label('Valor comprovado')
                    ->copyable(),
                TextColumn::make('document.title')
                    ->label('Documento')
                    ->wrap()
                    ->description(fn (EmissionPuBaselineEvidence $record): string => $record->document_type->label()),
                TextColumn::make('reference')
                    ->label('Página / cláusula')
                    ->placeholder('—')
                    ->wrap()
                    ->tooltip(fn (EmissionPuBaselineEvidence $record): ?string => $record->excerpt)
                    ->icon(fn (EmissionPuBaselineEvidence $record): ?string => $this->evidenceSourceUrl($record) !== null ? 'heroicon-m-arrow-top-right-on-square' : null)
                    ->iconPosition('after')
                    ->url(fn (EmissionPuBaselineEvidence $record): ?string => $this->evidenceSourceUrl($record), shouldOpenInNewTab: true),
                TextColumn::make('confidence')
                    ->label('Confiança')
                    ->formatStateUsing(fn (string $state): string => (PuBaselineEvidenceConfidence::tryFrom($state) ?? PuBaselineEvidenceConfidence::Low)->label())
                    ->badge()
                    ->color(fn (string $state): string => (PuBaselineEvidenceConfidence::tryFrom($state) ?? PuBaselineEvidenceConfidence::Low)->color()),
                TextColumn::make('value_origin')
                    ->label('Origem do valor')
                    ->formatStateUsing(fn (PuBaselineEvidenceValueOrigin $state): string => $state->label())
                    ->badge()
                    ->color(fn (PuBaselineEvidenceValueOrigin $state): string => $state->color())
                    ->placeholder('Não registrada')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Revisão')
                    ->formatStateUsing(fn (PuBaselineEvidenceStatus $state): string => $state->label())
                    ->badge()
                    ->color(fn (PuBaselineEvidenceStatus $state): string => match ($state) {
                        PuBaselineEvidenceStatus::Approved => 'success',
                        PuBaselineEvidenceStatus::Rejected => 'danger',
                        PuBaselineEvidenceStatus::PendingReview => 'warning',
                    }),
                TextColumn::make('reviewedBy.name')
                    ->label('Revisor')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reviewed_at')
                    ->label('Revisado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('evidence_type')
                    ->label('Requisito')
                    ->options(PuBaselineEvidenceType::options()),
                SelectFilter::make('status')
                    ->label('Revisão')
                    ->options(collect(PuBaselineEvidenceStatus::cases())
                        ->mapWithKeys(fn (PuBaselineEvidenceStatus $status): array => [$status->value => $status->label()])
                        ->all()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Associar evidência')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (): bool => auth()->user()?->can(AccessPermission::PuParametersConfigure->value) ?? false)
                    ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::PuParametersConfigure->value) ?? false)
                    ->modalHeading('Associar evidência ao baseline')
                    ->modalDescription('Informe exatamente o valor demonstrado pelo documento. A evidência seguirá pendente até revisão explícita.')
                    ->schema($this->evidenceForm())
                    ->using(function (array $data, RelationManager $livewire): EmissionPuBaselineEvidence {
                        /** @var User $actor */
                        $actor = auth()->user();

                        return app(PuBaselineEvidenceReviewService::class)->create(
                            $livewire->getOwnerRecord(),
                            $data,
                            $actor,
                        );
                    })
                    ->successNotificationTitle('Evidência associada e enviada para revisão.'),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Aprovar evidência')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    // Só há decisão a tomar sobre proposta pendente, e o maker da
                    // proposta não é checker dela: o serviço recusa a auto-aprovação,
                    // então a ação nem se oferece.
                    ->visible(fn (EmissionPuBaselineEvidence $record): bool => $record->status === PuBaselineEvidenceStatus::PendingReview
                        && $record->created_by !== auth()->id()
                        && (auth()->user()?->can(AccessPermission::PuCalendarHomologationReview->value) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('Aprovar evidência do baseline')
                    ->modalDescription('A aprovação torna o valor declarado utilizável pelo gate. Ela não cria parâmetros, curvas ou dados operacionais.')
                    ->schema([
                        Textarea::make('review_notes')
                            ->label('Conclusão da revisão')
                            ->rows(3),
                    ])
                    ->action(function (EmissionPuBaselineEvidence $record, array $data): void {
                        /** @var User $reviewer */
                        $reviewer = auth()->user();
                        app(PuBaselineEvidenceReviewService::class)->approve(
                            $record,
                            $reviewer,
                            $data['review_notes'] ?? null,
                        );

                        Notification::make()->title('Evidência aprovada.')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Rejeitar evidência')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (EmissionPuBaselineEvidence $record): bool => $record->status === PuBaselineEvidenceStatus::PendingReview
                        && $record->created_by !== auth()->id()
                        && (auth()->user()?->can(AccessPermission::PuCalendarHomologationReview->value) ?? false))
                    ->schema([
                        Textarea::make('review_notes')
                            ->label('Motivo da rejeição')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (EmissionPuBaselineEvidence $record, array $data): void {
                        /** @var User $reviewer */
                        $reviewer = auth()->user();
                        app(PuBaselineEvidenceReviewService::class)->reject(
                            $record,
                            $reviewer,
                            (string) $data['review_notes'],
                        );

                        Notification::make()->title('Evidência rejeitada.')->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nenhuma evidência material associada')
            ->emptyStateDescription('Data inicial da curva, quantidade integralizada e gabarito externo permanecem apenas inferíveis até que um documento idôneo seja associado e aprovado.')
            ->emptyStateIcon('heroicon-o-document-magnifying-glass');
    }

    /** @return array<int, Field|Component> */
    private function evidenceForm(): array
    {
        return [
            Select::make('evidence_type')
                ->label('Valor a comprovar')
                ->options(PuBaselineEvidenceType::options())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => $this->runExtraction($get, $set)),
            Select::make('document_id')
                ->label('Documento já vinculado à emissão')
                ->options(fn (): array => $this->getOwnerRecord()->documents()
                    ->orderBy('title')
                    ->pluck('title', 'documents.id')
                    ->all())
                ->searchable()
                ->required()
                ->live()
                // O indicador fica junto do campo, e não num componente próprio:
                // assim ele já existe na primeira seleção e não ocupa espaço no grid.
                ->belowContent(fn (Select $component): array => [
                    Text::make('Ao selecionar, o documento é analisado com IA (Google Gemini) para sugerir os campos abaixo. Nada é aprovado automaticamente.'),
                    new HtmlString(view(
                        'filament.resources.emissions.relation-managers.pu-baseline-evidence-analyzing',
                        ['targets' => $this->analysisLoadingTargets($component)],
                    )->render()),
                ])
                ->afterStateUpdated(fn (Get $get, Set $set) => $this->runExtraction($get, $set)),
            Hidden::make('extraction_id'),
            Callout::make(fn (Get $get): string => $this->extractionPanel($get)['heading'])
                ->key('aiExtraction')
                ->status(fn (Get $get): string => $this->extractionPanel($get)['status'])
                ->icon(fn (Get $get): string => $this->extractionPanel($get)['icon'])
                ->description(fn (Get $get): HtmlString => new HtmlString(view(
                    'filament.resources.emissions.relation-managers.pu-baseline-evidence-extraction',
                    $this->extractionPanel($get),
                )->render()))
                ->extraAttributes(fn (Callout $component): array => [
                    'wire:loading.remove' => '',
                    'wire:target' => $this->analysisLoadingTargets($component),
                ])
                ->actions([
                    Action::make('openEvidenceSource')
                        ->label(fn (Get $get): string => ($page = $this->matchingExtraction($get)['suggestion']['page'] ?? null) !== null
                            ? "Abrir evidência no documento (pág. {$page})"
                            : 'Abrir documento')
                        ->icon('heroicon-o-document-magnifying-glass')
                        ->link()
                        ->url(fn (Get $get): ?string => $this->extractionSourceUrl($get), shouldOpenInNewTab: true)
                        ->visible(fn (Get $get): bool => $this->extractionSourceUrl($get) !== null),
                    Action::make('reanalyzeWithAi')
                        ->label('Analisar novamente com IA')
                        ->icon('heroicon-o-sparkles')
                        ->color('gray')
                        ->size('sm')
                        ->visible(fn (Get $get): bool => PuBaselineEvidenceType::tryFrom((string) $get('evidence_type')) !== null)
                        // Mesmo padrão da nota explicativa: só pergunta quando há
                        // algo digitado pelo usuário que a nova sugestão substituiria.
                        ->requiresConfirmation(fn (Get $get): bool => $this->editedFields($get) !== [])
                        ->modalHeading('Substituir campos editados?')
                        ->modalDescription(fn (Get $get): string => 'Você editou: '
                            .collect($this->editedFields($get))->map(fn (string $field): string => self::FIELD_LABELS[$field])->implode(', ')
                            .'. A nova análise substituirá esses campos quando trouxer sugestão para eles; os demais permanecem como estão.')
                        ->modalSubmitActionLabel('Analisar e substituir')
                        ->action(fn (Get $get, Set $set) => $this->runExtraction($get, $set, replaceEdited: true, reuseCached: false)),
                ])
                ->visible(fn (Get $get): bool => filled($get('document_id'))),
            Select::make('document_type')
                ->label('Tipo do documento')
                ->options(PuBaselineEvidenceDocumentType::options())
                ->searchable()
                ->required()
                ->hint(fn (Get $get): ?string => $this->aiFieldHint('document_type', $get)['label'])
                ->hintIcon(fn (Get $get): ?string => $this->aiFieldHint('document_type', $get)['icon'])
                ->hintColor(fn (Get $get): ?string => $this->aiFieldHint('document_type', $get)['color']),
            TextInput::make('evidenced_value')
                ->label('Valor demonstrado')
                ->helperText('Data: AAAA-MM-DD. Quantidade: número positivo. Gabarito: available_pending_comparison, matched ou divergent.')
                ->required()
                ->maxLength(255)
                ->hint(fn (Get $get): ?string => $this->aiFieldHint('evidenced_value', $get)['label'])
                ->hintIcon(fn (Get $get): ?string => $this->aiFieldHint('evidenced_value', $get)['icon'])
                ->hintColor(fn (Get $get): ?string => $this->aiFieldHint('evidenced_value', $get)['color']),
            TextInput::make('reference')
                ->label('Página, cláusula ou referência da posição')
                ->maxLength(255)
                ->hint(fn (Get $get): ?string => $this->aiFieldHint('reference', $get)['label'])
                ->hintIcon(fn (Get $get): ?string => $this->aiFieldHint('reference', $get)['icon'])
                ->hintColor(fn (Get $get): ?string => $this->aiFieldHint('reference', $get)['color']),
            Select::make('confidence')
                ->label('Confiança da extração')
                ->options(PuBaselineEvidenceConfidence::options())
                ->default(PuBaselineEvidenceConfidence::High->value)
                ->required()
                ->hint(fn (Get $get): ?string => $this->aiFieldHint('confidence', $get)['label'])
                ->hintIcon(fn (Get $get): ?string => $this->aiFieldHint('confidence', $get)['icon'])
                ->hintColor(fn (Get $get): ?string => $this->aiFieldHint('confidence', $get)['color']),
            Textarea::make('notes')
                ->label('Observações')
                ->rows(3)
                ->maxLength(2000)
                ->hint(fn (Get $get): ?string => $this->aiFieldHint('notes', $get)['label'])
                ->hintIcon(fn (Get $get): ?string => $this->aiFieldHint('notes', $get)['icon'])
                ->hintColor(fn (Get $get): ?string => $this->aiFieldHint('notes', $get)['color']),
        ];
    }

    /**
     * Dispara a análise para a combinação selecionada e aplica a sugestão.
     *
     * Falha numa reanálise da mesma combinação não mexe no formulário: a
     * sugestão anterior continua valendo e só a notificação avisa. Já a falha
     * depois de trocar documento ou valor limpa o que a análise anterior tinha
     * preenchido, porque aquilo descrevia outro documento.
     */
    private function runExtraction(Get $get, Set $set, bool $replaceEdited = false, bool $reuseCached = true): void
    {
        $evidenceType = PuBaselineEvidenceType::tryFrom((string) $get('evidence_type'));
        $documentId = $get('document_id');

        if ($evidenceType === null || blank($documentId)) {
            return;
        }

        $previous = $this->currentExtraction($get);
        $sameSelection = $previous !== null && $this->matchesSelection($previous, $get);

        // Reselecionar a mesma combinação não reenvia o documento.
        if ($reuseCached && $sameSelection && $previous['status'] !== PuBaselineEvidenceExtractionService::STATUS_FAILED) {
            return;
        }

        $service = app(PuBaselineEvidenceExtractionService::class);
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $extraction = $service->analyze($this->getOwnerRecord(), $documentId, $evidenceType, $actor, $reuseCached);
        } catch (AuthorizationException|ValidationException $exception) {
            Notification::make()
                ->title('Não foi possível analisar este documento.')
                ->body($exception instanceof ValidationException
                    ? collect($exception->errors())->flatten()->first()
                    : $exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($extraction['status'] === PuBaselineEvidenceExtractionService::STATUS_FAILED && $sameSelection) {
            Notification::make()
                ->title($extraction['message'])
                ->body('A sugestão anterior e os campos preenchidos foram mantidos.')
                ->danger()
                ->send();

            return;
        }

        $fill = $service->formFill($this->formState($get), $previous['form'] ?? null, $extraction['form'], $replaceEdited);

        foreach ($fill['values'] as $field => $value) {
            $set($field, $value);
        }

        $set('extraction_id', $extraction['id']);
        $this->extractionMemo[$extraction['id']] = $extraction;
    }

    /** @return array<string, mixed>|null */
    private function currentExtraction(Get $get): ?array
    {
        $extractionId = $get('extraction_id');

        if (! is_string($extractionId) || $extractionId === '') {
            return null;
        }

        if (! array_key_exists($extractionId, $this->extractionMemo)) {
            $this->extractionMemo[$extractionId] = app(PuBaselineEvidenceExtractionService::class)->find($extractionId);
        }

        return $this->extractionMemo[$extractionId];
    }

    /**
     * A análise aplicada, desde que ainda descreva o documento e o valor
     * selecionados.
     *
     * @return array<string, mixed>|null
     */
    private function matchingExtraction(Get $get): ?array
    {
        $extraction = $this->currentExtraction($get);

        return $extraction !== null && $this->matchesSelection($extraction, $get) ? $extraction : null;
    }

    /** @param array<string, mixed> $extraction */
    private function matchesSelection(array $extraction, Get $get): bool
    {
        return (string) $extraction['document_id'] === (string) $get('document_id')
            && $extraction['evidence_type'] === (string) $get('evidence_type');
    }

    /** @return array<string, mixed> */
    private function formState(Get $get): array
    {
        return collect(PuBaselineEvidenceExtractionService::FORM_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $get($field)])
            ->all();
    }

    /** @return list<string> */
    private function editedFields(Get $get): array
    {
        return app(PuBaselineEvidenceExtractionService::class)
            ->editedFields($this->formState($get), $this->currentExtraction($get)['form'] ?? null);
    }

    /**
     * @return array{heading: string, status: string, icon: string, message: ?string, extraction: array<string, mixed>|null, divergences: list<array{label: string, suggested: string}>, occurrences: list<array{value: string, page: ?int, excerpt: ?string, url: ?string}>, documentOpenable: bool}
     */
    private function extractionPanel(Get $get): array
    {
        $extraction = $this->matchingExtraction($get);
        $panel = ['message' => null, 'extraction' => $extraction, 'divergences' => [], 'occurrences' => [], 'documentOpenable' => true];

        if (PuBaselineEvidenceType::tryFrom((string) $get('evidence_type')) === null) {
            return [...$panel,
                'heading' => 'Selecione o valor a comprovar',
                'status' => 'info',
                'icon' => 'heroicon-o-information-circle',
                'message' => 'A IA precisa saber qual valor procurar no documento antes de analisá-lo.',
            ];
        }

        if ($extraction === null) {
            return [...$panel,
                'heading' => 'Análise com IA não executada',
                'status' => 'info',
                'icon' => 'heroicon-o-sparkles',
                'message' => 'Use "Analisar novamente com IA" para analisar este documento, ou preencha os campos manualmente.',
            ];
        }

        $panel['message'] = $extraction['message'];
        $panel['documentOpenable'] = $this->extractionSourceUrl($get) !== null;
        $panel['occurrences'] = collect($extraction['suggestion']['alternatives'] ?? [])
            ->map(fn (array $occurrence): array => [
                ...$occurrence,
                'url' => $occurrence['page'] !== null ? $this->extractionSourceUrl($get, $occurrence['page']) : null,
            ])
            ->all();
        $panel['divergences'] = collect($this->editedFields($get))
            ->filter(fn (string $field): bool => ($extraction['form'][$field] ?? null) !== null)
            ->map(fn (string $field): array => [
                'label' => self::FIELD_LABELS[$field],
                'suggested' => $this->displayValue($field, (string) $extraction['form'][$field]),
            ])
            ->values()
            ->all();
        $confidence = PuBaselineEvidenceConfidence::tryFrom((string) ($extraction['suggestion']['confidence'] ?? ''));

        return match ($extraction['status']) {
            PuBaselineEvidenceExtractionService::STATUS_FOUND => [...$panel,
                'heading' => 'Evidência encontrada · confiança '.mb_strtolower(($confidence ?? PuBaselineEvidenceConfidence::Low)->label()),
                'status' => $confidence === PuBaselineEvidenceConfidence::High ? 'success' : 'warning',
                'icon' => 'heroicon-o-check-circle',
            ],
            PuBaselineEvidenceExtractionService::STATUS_PARTIAL => [...$panel,
                'heading' => 'Resultado parcial',
                'status' => 'warning',
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            PuBaselineEvidenceExtractionService::STATUS_NOT_FOUND => [...$panel,
                'heading' => 'Informação não localizada',
                'status' => 'info',
                'icon' => 'heroicon-o-document-magnifying-glass',
            ],
            default => [...$panel,
                'heading' => 'Análise indisponível',
                'status' => 'danger',
                'icon' => 'heroicon-o-x-circle',
            ],
        };
    }

    /**
     * @return array{label: ?string, icon: ?string, color: ?string}
     */
    private function aiFieldHint(string $field, Get $get): array
    {
        $suggested = $this->matchingExtraction($get)['form'][$field] ?? null;

        if ($suggested === null) {
            return ['label' => null, 'icon' => null, 'color' => null];
        }

        return trim((string) $get($field)) === $suggested
            ? ['label' => 'Preenchido automaticamente por IA', 'icon' => 'heroicon-m-sparkles', 'color' => 'primary']
            : ['label' => 'Editado após sugestão da IA', 'icon' => 'heroicon-m-pencil-square', 'color' => 'warning'];
    }

    private function displayValue(string $field, string $value): string
    {
        return match ($field) {
            'document_type' => PuBaselineEvidenceDocumentType::tryFrom($value)?->label() ?? $value,
            'confidence' => PuBaselineEvidenceConfidence::tryFrom($value)?->label() ?? $value,
            default => $value,
        };
    }

    /**
     * Só abre quando a análise localizou algo neste documento, o arquivo passou
     * pela varredura e o usuário pode ver documentos — a rota de preview exige
     * as duas coisas e responderia 404/403 no lugar do documento.
     */
    private function extractionSourceUrl(Get $get, ?int $page = null): ?string
    {
        $extraction = $this->matchingExtraction($get);

        if ($extraction === null
            || ! in_array($extraction['status'], [PuBaselineEvidenceExtractionService::STATUS_FOUND, PuBaselineEvidenceExtractionService::STATUS_PARTIAL], true)
            || ! (auth()->user()?->can(AccessPermission::DocumentsView->value) ?? false)) {
            return null;
        }

        $documentId = (int) $extraction['document_id'];

        if (! array_key_exists($documentId, $this->documentMemo)) {
            $this->documentMemo[$documentId] = $this->getOwnerRecord()->documents()->whereKey($documentId)->first();
        }

        $document = $this->documentMemo[$documentId];

        return $document instanceof Document
            ? PuBaselineEvidenceExtractionService::sourceUrl($document, $page ?? $extraction['suggestion']['page'] ?? null)
            : null;
    }

    private function evidenceSourceUrl(EmissionPuBaselineEvidence $record): ?string
    {
        return $record->page !== null && (auth()->user()?->can(AccessPermission::DocumentsView->value) ?? false)
            ? $record->source_url
            : null;
    }

    /**
     * O indicador de análise aparece enquanto a troca de documento ou de valor
     * está em voo — é nessa requisição que a análise automática roda.
     */
    private function analysisLoadingTargets(Field|Component $component): string
    {
        $statePath = $component->getContainer()->getStatePath();

        return "{$statePath}.evidence_type, {$statePath}.document_id";
    }
}
