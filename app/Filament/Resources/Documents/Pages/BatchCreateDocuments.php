<?php

namespace App\Filament\Resources\Documents\Pages;

use App\DTOs\Documents\DocumentBatchDefaults;
use App\DTOs\Documents\DocumentBatchItem;
use App\DTOs\Documents\DocumentBatchItemOutcome;
use App\DTOs\Documents\DocumentBatchResult;
use App\Enums\DocumentBatchItemStatus;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Models\Emission;
use App\Services\Documents\DocumentBatchAnalyzer;
use App\Services\Documents\DocumentBatchCreator;
use App\Services\Documents\DocumentBatchLimits;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Cadastro de vários documentos de uma vez, sem substituir o cadastro
 * individual — que continua sendo a página de criação padrão do Resource.
 *
 * A página só orquestra: os dados comuns são informados uma vez, os arquivos
 * são conferidos um a um e a criação é delegada ao {@see DocumentBatchCreator},
 * que reaproveita as regras do model (armazenamento privado, metadados
 * derivados do disco, antivírus síncrono, log de atividade).
 *
 * Confirmação duplicada por clique repetido é barrada em dois níveis: o botão
 * do Filament sai com `wire:loading.attr="disabled"` enquanto a requisição
 * corre, e os arquivos já convertidos em documento ficam registrados em
 * `$createdFileKeys` — um segundo envio nunca os reprocessa.
 */
class BatchCreateDocuments extends Page
{
    protected static string $resource = DocumentResource::class;

    protected static ?string $title = 'Cadastrar documentos em lote';

    protected static ?string $breadcrumb = 'Cadastro em lote';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Resumo do último processamento, em forma serializável.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $outcomes = [];

    /**
     * Chaves de arquivo que já viraram documento nesta sessão da página.
     *
     * @var array<int, string>
     */
    public array $createdFileKeys = [];

    public ?string $previousUrl = null;

    public static function canAccess(array $parameters = []): bool
    {
        return static::getResource()::canCreate();
    }

    public function mount(): void
    {
        $this->authorizeAccess();

        $this->form->fill();

        $this->previousUrl = url()->previous();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canCreate(), 403);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.documents.pages.batch-create-documents-result')
                ->visible(fn (): bool => $this->outcomes !== []),
            EmbeddedSchema::make('form'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(null)
            ->statePath('data')
            ->components([
                Wizard::make([
                    $this->getBatchDataStep(),
                    $this->getReviewStep(),
                ])
                    ->submitAction($this->getSubmitFormAction())
                    ->cancelAction($this->getCancelFormAction())
                    ->alpineSubmitHandler('$wire.create()')
                    ->contained(false),
            ]);
    }

    protected function getBatchDataStep(): Step
    {
        $limits = $this->limits();

        return Step::make('Dados do lote')
            ->description('Informações comuns e seleção dos arquivos')
            ->icon(Heroicon::OutlinedRectangleStack)
            ->schema([
                Section::make('Dados aplicados a todos os documentos')
                    ->description('Estes valores são gravados igualmente em cada documento do lote.')
                    ->schema([
                        Select::make('category')
                            ->label('Categoria')
                            ->options(Document::CATEGORY_OPTIONS)
                            ->searchable()
                            ->required()
                            ->validationMessages([
                                'required' => 'Selecione a categoria dos documentos do lote.',
                            ]),

                        Select::make('emissions')
                            ->label('Série')
                            ->options(fn (): array => Emission::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->placeholder('Selecione uma ou mais séries')
                            // O vínculo é aplicado a todos os documentos do
                            // lote: uma série inexistente derrubaria cada
                            // documento no `sync()`, um a um, em vez de virar
                            // um erro único no formulário.
                            ->nestedRecursiveRules(['integer', 'exists:emissions,id']),

                        Callout::make()
                            ->info()
                            ->heading('Todos os documentos entram como rascunho')
                            ->description('Nenhum documento do lote é publicado no portal nem no site público. A publicação continua sendo feita documento a documento na listagem.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Arquivos')
                    ->description($limits->summaryText())
                    ->schema([
                        FileUpload::make('upload')
                            ->label('Arquivos do lote')
                            ->hiddenLabel()
                            ->multiple()
                            ->storeFiles(false)
                            ->required()
                            ->panelLayout('list')
                            ->reorderable(false)
                            ->acceptedFileTypes($limits->allowedMimeTypes())
                            ->maxFiles($limits->maxFiles())
                            ->maxSize($limits->maxFileKilobytes())
                            ->helperText('Selecione vários arquivos de uma vez. Eles só são gravados quando você confirmar o cadastro na etapa de conferência.')
                            ->validationMessages([
                                'required' => 'Selecione ao menos um arquivo.',
                                'max' => "O lote aceita no máximo {$limits->maxFiles()} arquivos.",
                            ])
                            ->columnSpanFull(),

                        Text::make(fn (Get $get): string => $this->totalSizeSummary($get))
                            ->columnSpanFull(),
                    ]),
            ])
            ->afterValidation(function (): void {
                $this->syncReviewItems();
            });
    }

    protected function getReviewStep(): Step
    {
        return Step::make('Conferência')
            ->description('Revise cada arquivo antes de confirmar')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->schema([
                Callout::make()
                    ->danger()
                    ->heading(fn (Get $get): string => $this->rejectedCount($get).' arquivo(s) serão rejeitados')
                    ->description('Os itens marcados abaixo não serão cadastrados. Os demais seguem normalmente — o processamento é independente por arquivo.')
                    ->visible(fn (Get $get): bool => $this->rejectedCount($get) > 0)
                    ->columnSpanFull(),

                Repeater::make('files')
                    ->hiddenLabel()
                    // As linhas nascem da etapa anterior, uma por arquivo
                    // enviado; sem isto o Filament abriria um item vazio.
                    ->defaultItems(0)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->itemLabel(fn (array $state): ?string => $state['original_name'] ?? null)
                    ->schema([
                        // Os campos abaixo do título são apenas o retrato do
                        // arquivo mostrado na conferência. Eles voltam do estado
                        // do formulário, que é controlável pelo cliente, e por
                        // isso nenhum deles decide nada: o processamento
                        // reinspeciona o arquivo de verdade.
                        Hidden::make('file_key'),
                        Hidden::make('original_name'),
                        Hidden::make('extension'),
                        Hidden::make('size'),
                        Hidden::make('error'),
                        Hidden::make('warning'),

                        TextInput::make('title')
                            ->label('Título do documento')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Sugerido a partir do nome do arquivo, sem a extensão.')
                            ->columnSpan(2),

                        Text::make(fn (Get $get): string => $this->fileFactsSummary($get))
                            ->columnSpan(2),

                        Text::make(fn (Get $get): string => (string) $get('error'))
                            ->color('danger')
                            ->weight(FontWeight::SemiBold)
                            ->visible(fn (Get $get): bool => filled($get('error')))
                            ->columnSpanFull(),

                        Text::make(fn (Get $get): string => (string) $get('warning'))
                            ->color('warning')
                            ->visible(fn (Get $get): bool => filled($get('warning')))
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * O rótulo e o estado do botão são fechamentos porque o schema é montado
     * antes de `create()` rodar: avaliados só na renderização, eles já refletem
     * o resultado do processamento na mesma requisição.
     */
    protected function getSubmitFormAction(): Action
    {
        return Action::make('create')
            ->label(fn (): string => $this->outcomes === []
                ? 'Cadastrar documentos'
                : 'Reprocessar pendentes ('.count($this->retryableKeys()).')')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->disabled(fn (): bool => $this->outcomes !== [] && $this->retryableKeys() === [])
            ->action('create')
            ->keyBindings(['mod+s']);
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->color('gray')
            ->url($this->previousUrl ?? static::getResource()::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('individual_create')
                ->label('Cadastro individual')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->color('gray')
                ->url(fn (): string => static::getResource()::getUrl('create')),
        ];
    }

    /**
     * Reconstrói as linhas da conferência a partir dos arquivos enviados,
     * preservando os títulos já editados dos arquivos que continuam no lote.
     */
    public function syncReviewItems(): void
    {
        $files = $this->resolveUploadedFiles($this->data['upload'] ?? []);
        $analyses = app(DocumentBatchAnalyzer::class)->analyze($files);

        $existingTitles = [];

        foreach ((array) ($this->data['files'] ?? []) as $row) {
            $key = (string) ($row['file_key'] ?? '');

            if ($key !== '') {
                $existingTitles[$key] = (string) ($row['title'] ?? '');
            }
        }

        $rows = [];

        foreach ($files as $key => $file) {
            $analysis = $analyses[$key];
            $title = trim($existingTitles[$key] ?? '');

            $rows[] = [
                'file_key' => $key,
                'title' => $title !== ''
                    ? $title
                    : DocumentBatchItem::titleFromFileName($analysis->originalName),
                'original_name' => $analysis->originalName,
                'extension' => $analysis->extension,
                'size' => $analysis->sizeBytes,
                'error' => $analysis->error,
                'warning' => $analysis->isDuplicatedInBatch()
                    ? 'Arquivo idêntico a outro já incluído neste lote; ele não será cadastrado duas vezes.'
                    : $analysis->duplicateWarning,
            ];
        }

        $this->data['files'] = $rows;
    }

    public function create(): void
    {
        $this->authorizeAccess();

        // Um arquivo reprovado não pode derrubar o lote inteiro na validação do
        // formulário: ele sai do envio com o motivo registrado e os demais
        // seguem. É o mesmo princípio do processamento — independente por
        // arquivo — aplicado uma etapa antes.
        $rejectedOutcomes = $this->pullRejectedUploads();

        $this->mergeOutcomes($rejectedOutcomes);

        if ($this->resolveUploadedFiles($this->data['upload'] ?? []) === []) {
            $rejectedOutcomes === []
                ? $this->sendNothingToProcessNotification()
                : $this->sendResultNotification(new DocumentBatchResult($rejectedOutcomes));

            return;
        }

        $state = $this->form->getState();

        $items = $this->resolveBatchItems($state);
        $isRetry = $this->createdFileKeys !== [] || $this->hasRetryableOutcomes();

        if ($isRetry) {
            $retryableKeys = $this->retryableKeys();

            $items = array_values(array_filter(
                $items,
                static fn (DocumentBatchItem $item): bool => in_array($item->key, $retryableKeys, true),
            ));
        }

        $items = array_values(array_filter(
            $items,
            fn (DocumentBatchItem $item): bool => ! in_array($item->key, $this->createdFileKeys, true),
        ));

        if ($items === []) {
            $rejectedOutcomes === []
                ? $this->sendNothingToProcessNotification()
                : $this->sendResultNotification(new DocumentBatchResult($rejectedOutcomes));

            return;
        }

        $rejectionReason = $this->limits()->batchRejectionReason(array_map(
            static fn (DocumentBatchItem $item): int => (int) $item->file->getSize(),
            $items,
        ));

        if ($rejectionReason !== null) {
            Notification::make()
                ->title('Lote acima do limite')
                ->body($rejectionReason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $result = app(DocumentBatchCreator::class)->create(
            $items,
            DocumentBatchDefaults::fromFormData($state),
        );

        $this->mergeOutcomes($result->outcomes);

        $this->sendResultNotification(new DocumentBatchResult([
            ...$rejectedOutcomes,
            ...$result->outcomes,
        ]));
    }

    /**
     * Retira do envio os arquivos que a análise reprova e devolve o desfecho de
     * cada um, para que o resumo mostre o motivo individual.
     *
     * @return array<int, DocumentBatchItemOutcome>
     */
    protected function pullRejectedUploads(): array
    {
        $files = $this->resolveUploadedFiles($this->data['upload'] ?? []);

        if ($files === []) {
            return [];
        }

        // Só a validação por arquivo, não a análise completa: a detecção de
        // duplicidade lê o conteúdo inteiro de cada arquivo, e quem decide sobre
        // duplicidade é o processamento — repetir isso aqui seria uma leitura a
        // mais do lote inteiro sem mudar nenhuma decisão.
        $analyzer = app(DocumentBatchAnalyzer::class);
        $reviewedTitles = $this->reviewedTitles();

        $outcomes = [];

        foreach ($files as $key => $file) {
            $error = $analyzer->validationErrorFor($file);

            if ($error === null) {
                continue;
            }

            $originalName = $file->getClientOriginalName();

            $outcomes[] = new DocumentBatchItemOutcome(
                key: $key,
                originalName: $originalName,
                title: $reviewedTitles[$key] ?? DocumentBatchItem::titleFromFileName($originalName),
                status: DocumentBatchItemStatus::Rejected,
                reason: $error,
            );

            unset($this->data['upload'][$key]);
        }

        if ($outcomes === []) {
            return [];
        }

        $rejectedKeys = array_map(
            static fn (DocumentBatchItemOutcome $outcome): string => $outcome->key,
            $outcomes,
        );

        $this->data['files'] = array_values(array_filter(
            (array) ($this->data['files'] ?? []),
            static fn (array $row): bool => ! in_array((string) ($row['file_key'] ?? ''), $rejectedKeys, true),
        ));

        return $outcomes;
    }

    /**
     * @return array<string, string>
     */
    protected function reviewedTitles(): array
    {
        $titles = [];

        foreach ((array) ($this->data['files'] ?? []) as $row) {
            $key = (string) ($row['file_key'] ?? '');
            $title = trim((string) ($row['title'] ?? ''));

            if ($key !== '' && $title !== '') {
                $titles[$key] = $title;
            }
        }

        return $titles;
    }

    public function startNewBatch(): void
    {
        $this->authorizeAccess();

        $this->outcomes = [];
        $this->createdFileKeys = [];
        $this->data = [];

        $this->form->fill();
    }

    /**
     * Somas exibidas no resumo final.
     *
     * @return array<string, int>
     */
    public function outcomeTotals(): array
    {
        $totals = [];

        foreach (DocumentBatchItemStatus::cases() as $status) {
            $totals[$status->value] = count(array_filter(
                $this->outcomes,
                static fn (array $outcome): bool => $outcome['status'] === $status->value,
            ));
        }

        return $totals;
    }

    public function hasRetryableOutcomes(): bool
    {
        return $this->retryableKeys() !== [];
    }

    public function listDocumentsUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * @return array<int, string>
     */
    protected function retryableKeys(): array
    {
        return array_values(array_map(
            static fn (array $outcome): string => (string) $outcome['key'],
            array_filter($this->outcomes, static fn (array $outcome): bool => (bool) $outcome['is_retryable']),
        ));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, DocumentBatchItem>
     */
    protected function resolveBatchItems(array $state): array
    {
        $files = $this->resolveUploadedFiles($state['upload'] ?? []);

        $titles = [];

        foreach ((array) ($state['files'] ?? []) as $row) {
            $key = (string) ($row['file_key'] ?? '');

            if ($key !== '') {
                $titles[$key] = (string) ($row['title'] ?? '');
            }
        }

        $items = [];

        foreach ($files as $key => $file) {
            $items[] = new DocumentBatchItem(
                key: $key,
                file: $file,
                title: $titles[$key] ?? DocumentBatchItem::titleFromFileName($file->getClientOriginalName()),
            );
        }

        return $items;
    }

    /**
     * Só arquivos realmente enviados nesta sessão entram no lote: um valor de
     * outro tipo no estado do formulário é descartado em vez de virar caminho.
     *
     * @return array<string, TemporaryUploadedFile>
     */
    protected function resolveUploadedFiles(mixed $state): array
    {
        $files = [];

        foreach ((array) $state as $key => $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $files[(string) $key] = $file;
            }
        }

        return $files;
    }

    /**
     * @param  array<int, DocumentBatchItemOutcome>  $outcomes
     */
    protected function mergeOutcomes(array $outcomes): void
    {
        if ($outcomes === []) {
            return;
        }

        $outcomesByKey = [];

        foreach ($this->outcomes as $outcome) {
            $outcomesByKey[(string) $outcome['key']] = $outcome;
        }

        foreach ($outcomes as $outcome) {
            $outcomesByKey[$outcome->key] = $outcome->toArray();

            if ($outcome->status === DocumentBatchItemStatus::Created) {
                $this->createdFileKeys[] = $outcome->key;
            }
        }

        $this->outcomes = array_values($outcomesByKey);
        $this->createdFileKeys = array_values(array_unique($this->createdFileKeys));
    }

    protected function sendNothingToProcessNotification(): void
    {
        Notification::make()
            ->title('Nada a processar')
            ->body('Todos os arquivos deste lote já foram processados.')
            ->warning()
            ->send();
    }

    protected function sendResultNotification(DocumentBatchResult $result): void
    {
        $created = $result->createdCount();
        $remaining = count($result->outcomes) - $created;

        $notification = Notification::make()
            ->title($created > 0
                ? "{$created} documento(s) cadastrado(s) como rascunho"
                : 'Nenhum documento foi cadastrado')
            ->body($remaining > 0
                ? "{$remaining} arquivo(s) não foram cadastrados. Veja o motivo de cada um no resumo abaixo."
                : 'Todos os arquivos do lote foram cadastrados.');

        match (true) {
            $created === 0 => $notification->danger(),
            $result->isPartialSuccess() => $notification->warning()->persistent(),
            default => $notification->success(),
        };

        $notification->send();
    }

    protected function limits(): DocumentBatchLimits
    {
        return app(DocumentBatchLimits::class);
    }

    protected function totalSizeSummary(Get $get): string
    {
        $files = $this->resolveUploadedFiles($get('upload'));

        if ($files === []) {
            return 'Nenhum arquivo selecionado.';
        }

        $limits = $this->limits();
        $totalBytes = array_sum(array_map(
            static fn (TemporaryUploadedFile $file): int => (int) $file->getSize(),
            $files,
        ));

        $summary = count($files).' arquivo(s) · '.Number::fileSize($totalBytes)
            .' de '.Number::fileSize($limits->maxTotalBytes()).' permitidos no lote.';

        return $totalBytes > $limits->maxTotalBytes()
            ? $summary.' Remova arquivos para conseguir confirmar o cadastro.'
            : $summary;
    }

    protected function rejectedCount(Get $get): int
    {
        return count(array_filter(
            (array) $get('files'),
            static fn (array $row): bool => filled($row['error'] ?? null),
        ));
    }

    protected function fileFactsSummary(Get $get): string
    {
        $extension = (string) $get('extension');
        $size = (int) $get('size');

        return trim(implode(' · ', array_filter([
            'Arquivo: '.$get('original_name'),
            $extension === '' ? null : 'Tipo: .'.$extension,
            'Tamanho: '.Number::fileSize($size),
        ])));
    }
}
