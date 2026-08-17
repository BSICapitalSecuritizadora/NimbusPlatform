<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentDocumentStatus;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Jobs\ProcessLegalInstrumentDocumentJob;
use App\Models\Document;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use App\Services\LegalInstruments\ExistingDocumentScanner;
use App\Services\LegalInstruments\InstrumentPositionResolver;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Placeholder;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Instrumentos jurídicos da emissão e seus dossiês (§3 e §40 do escopo).
 *
 * A tabela responde à visão executiva do §41 — qual instrumento, de quem, quanto
 * vale hoje, quando vence, quantos aditamentos, o que falta revisar. O
 * detalhamento fica em modais para não sobrecarregar a listagem.
 */
class LegalInstrumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'legalInstruments';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Instrumentos Jurídicos';

    protected static ?string $modelLabel = 'Instrumento';

    protected static ?string $pluralModelLabel = 'Instrumentos Jurídicos';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::LegalInstrumentsView->value) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('type')
                ->label('Tipo do instrumento')
                ->options(LegalInstrumentType::options())
                ->searchable()
                ->required()
                ->live(),
            TextInput::make('number')
                ->label('Número')
                ->maxLength(255)
                ->placeholder('Ex: 001/2026'),
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255)
                ->placeholder('Ex: CCB nº 001/2026'),
            Select::make('status')
                ->label('Situação')
                ->options(LegalInstrument::STATUS_OPTIONS)
                ->default(LegalInstrument::STATUS_ACTIVE)
                ->required(),
            Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull(),
            Textarea::make('notes')->label('Notas internas')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->poll($this->hasProcessingDocuments() ? '5s' : null)
            ->description('Cada instrumento reúne o documento original, seus aditamentos e as garantias que ele constitui.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['documents.document', 'guarantees', 'fields']))
            ->columns([
                TextColumn::make('name')
                    ->label('Instrumento')
                    ->formatStateUsing(fn (LegalInstrument $record): string => $record->display_name)
                    ->description(fn (LegalInstrument $record): string => $record->type->label())
                    ->searchable()
                    ->wrap(),
                TextColumn::make('documents_count')
                    ->label('Dossiê')
                    ->state(fn (LegalInstrument $record): string => $this->dossierSummary($record))
                    ->wrap(),
                TextColumn::make('guarantees_count')
                    ->label('Garantias')
                    ->state(fn (LegalInstrument $record): int => $record->guarantees->count())
                    ->alignCenter(),
                TextColumn::make('pending_changes')
                    ->label('Alterações pendentes')
                    ->badge()
                    ->state(fn (LegalInstrument $record): string => (string) $record->fields
                        ->where('status', LegalInstrumentFieldStatus::PendingReview)
                        ->count())
                    ->color(fn (string $state): string => $state === '0' ? 'gray' : 'warning')
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (LegalInstrument $record): string => $record->status_label)
                    ->color(fn (LegalInstrument $record): string => $record->status === LegalInstrument::STATUS_ACTIVE ? 'success' : 'gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                $this->makeScanExistingDocumentsAction(),
                CreateAction::make()
                    ->label('Cadastrar instrumento')
                    ->authorize(fn (): bool => $this->userCan(AccessPermission::LegalInstrumentsCreate)),
            ])
            ->actions([
                $this->makeCurrentPositionAction(),
                $this->makeDossierAction(),
                $this->makeAttachDocumentAction(),
                $this->makeReprocessAction(),
                $this->makeHistoryAction(),
                EditAction::make()
                    ->authorize(fn (): bool => $this->userCan(AccessPermission::LegalInstrumentsUpdate)),
                DeleteAction::make()
                    ->authorize(fn (): bool => $this->userCan(AccessPermission::LegalInstrumentsDelete)),
            ])
            ->emptyStateHeading('Nenhum instrumento cadastrado')
            ->emptyStateDescription('Cadastre a CCB, o contrato de alienação fiduciária ou outro instrumento e monte o dossiê com o documento original e seus aditamentos.');
    }

    /**
     * Visão executiva: responde às dez perguntas do §41 numa tela só.
     */
    protected function makeCurrentPositionAction(): Action
    {
        return Action::make('current_position')
            ->label('Posição vigente')
            ->icon('heroicon-o-document-check')
            ->color('primary')
            ->modalHeading(fn (LegalInstrument $record): string => $record->display_name)
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->form([
                DatePicker::make('as_of')
                    ->label('Posição na data')
                    ->helperText('Deixe a data de hoje para a posição vigente, ou escolha outra para reconstruir a situação histórica.')
                    ->default(now()),
            ])
            ->modalContent(fn (LegalInstrument $record, array $data): View => view(
                'filament.resources.emissions.relation-managers.legal-instrument-position',
                [
                    'instrument' => $record,
                    'position' => app(InstrumentPositionResolver::class)->resolve($record, $data['as_of'] ?? null),
                    'resolver' => app(InstrumentPositionResolver::class),
                ],
            ));
    }

    /**
     * Monta dossiês a partir dos documentos que a emissão já tem (§36).
     *
     * A varredura olha nome e título — não abre o PDF — e nada é criado sem
     * confirmação: a tela lista o que foi reconhecido e o usuário escolhe quais
     * grupos virar dossiê.
     */
    protected function makeScanExistingDocumentsAction(): Action
    {
        return Action::make('scan_existing_documents')
            ->label('Analisar documentos existentes')
            ->icon('heroicon-o-magnifying-glass-circle')
            ->color('gray')
            ->authorize(fn (): bool => $this->userCan(AccessPermission::LegalInstrumentsCreate))
            ->modalHeading('Analisar documentos existentes')
            ->modalDescription('Procura, entre os documentos já anexados à emissão, os que parecem pertencer a um instrumento jurídico. Nada é criado sem a sua confirmação.')
            ->modalSubmitActionLabel('Criar dossiês selecionados')
            ->form(function (): array {
                $suggestions = app(ExistingDocumentScanner::class)->scan($this->getOwnerRecord());

                if ($suggestions->isEmpty()) {
                    return [
                        Placeholder::make('empty')
                            ->label('')
                            ->content('Nenhum documento reconhecível encontrado. Documentos já vinculados a um dossiê não aparecem aqui.'),
                    ];
                }

                return [
                    CheckboxList::make('types')
                        ->label('Instrumentos reconhecidos')
                        ->options($suggestions
                            ->mapWithKeys(fn (array $group, string $type): array => [
                                $type => sprintf(
                                    '%s — %s',
                                    $group['type']->label(),
                                    $group['documents']
                                        ->map(fn (array $entry): string => $entry['role'] === LegalInstrumentDocumentRole::Amendment && $entry['sequence'] !== null
                                            ? "{$entry['sequence']}º Aditamento"
                                            : $entry['role']->label())
                                        ->implode(', '),
                                ),
                            ])
                            ->all())
                        ->columns(1)
                        ->required(),
                ];
            })
            ->action(function (array $data): void {
                $selected = $data['types'] ?? [];

                if ($selected === []) {
                    return;
                }

                $suggestions = app(ExistingDocumentScanner::class)->scan($this->getOwnerRecord());
                $createdInstruments = 0;
                $attachedDocuments = 0;

                foreach ($selected as $typeValue) {
                    $group = $suggestions->get($typeValue);

                    if ($group === null) {
                        continue;
                    }

                    /** @var LegalInstrument $instrument */
                    $instrument = $this->getOwnerRecord()->legalInstruments()->create([
                        'type' => $group['type'],
                        'name' => $group['type']->label(),
                        'status' => LegalInstrument::STATUS_ACTIVE,
                        'created_by' => auth()->id(),
                    ]);

                    $createdInstruments++;

                    foreach ($group['documents'] as $entry) {
                        /** @var LegalInstrumentDocument $instrumentDocument */
                        $instrumentDocument = $instrument->documents()->create([
                            'document_id' => $entry['document']->id,
                            'role' => $entry['role'],
                            'sequence' => $entry['sequence'],
                            'processing_status' => LegalInstrumentDocumentStatus::Pending,
                            'message' => 'Aguardando processamento...',
                            'added_by' => auth()->id(),
                        ]);

                        ProcessLegalInstrumentDocumentJob::dispatch($instrumentDocument->getKey());
                        $attachedDocuments++;
                    }

                    activity('legal_instrument_documents')
                        ->causedBy(auth()->user())
                        ->performedOn($instrument)
                        ->event('dossier_created_from_scan')
                        ->withProperties([
                            'emission_id' => $this->getOwnerRecord()->getKey(),
                            'documents' => $group['documents']->count(),
                        ])
                        ->log('Dossiê criado a partir dos documentos existentes');
                }

                Notification::make()
                    ->title('Dossiês criados')
                    ->body("{$createdInstruments} instrumento(s) e {$attachedDocuments} documento(s). A leitura foi enfileirada.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Reenfileira a leitura de um documento do dossiê (§38 do escopo).
     *
     * Serve tanto para retomar uma falha quanto para reler o documento depois
     * de uma melhoria no extrator. Reprocessar não apaga nada: as informações
     * já confirmadas permanecem, e o que a nova leitura trouxer de diferente
     * entra como proposta pendente de revisão.
     */
    protected function makeReprocessAction(): Action
    {
        return Action::make('reprocess_document')
            ->label('Reprocessar documento')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->authorize(fn (): bool => $this->userCan(AccessPermission::LegalInstrumentsProcessDocument))
            ->visible(fn (LegalInstrument $record): bool => $record->documents->isNotEmpty())
            ->modalHeading('Reprocessar documento do dossiê')
            ->modalDescription('A releitura não altera informações já confirmadas. Diferenças em relação à posição vigente entram como alterações pendentes de revisão.')
            ->modalSubmitActionLabel('Reprocessar')
            ->form(fn (LegalInstrument $record): array => [
                Select::make('legal_instrument_document_id')
                    ->label('Documento')
                    ->options($record->documents
                        ->mapWithKeys(fn (LegalInstrumentDocument $entry): array => [
                            $entry->getKey() => sprintf(
                                '%s — %s (%s)',
                                $entry->role_label,
                                $entry->title,
                                $entry->processing_status?->label() ?? '—',
                            ),
                        ])
                        ->all())
                    ->required(),
            ])
            ->action(function (LegalInstrument $record, array $data): void {
                /** @var LegalInstrumentDocument|null $entry */
                $entry = $record->documents()->whereKey($data['legal_instrument_document_id'])->first();

                if ($entry === null) {
                    return;
                }

                $entry->forceFill([
                    'processing_status' => LegalInstrumentDocumentStatus::Pending,
                    'current_step' => 'queued',
                    'message' => 'Reprocessamento aguardando execução...',
                    'error_message' => null,
                ])->save();

                activity('legal_instrument_documents')
                    ->causedBy(auth()->user())
                    ->performedOn($entry)
                    ->event('document_reprocessed')
                    ->withProperties([
                        'legal_instrument_id' => $record->getKey(),
                        'document_id' => $entry->document_id,
                        'previous_attempts' => $entry->extraction_attempts,
                    ])
                    ->log('Documento do dossiê reprocessado');

                ProcessLegalInstrumentDocumentJob::dispatch($entry->getKey());

                Notification::make()
                    ->title('Reprocessamento enfileirado')
                    ->body('Acompanhe o andamento no dossiê. As diferenças aparecerão em "Alterações Detectadas".')
                    ->info()
                    ->send();
            });
    }

    protected function makeDossierAction(): Action
    {
        return Action::make('dossier')
            ->label('Dossiê')
            ->icon('heroicon-o-folder-open')
            ->color('gray')
            ->modalHeading(fn (LegalInstrument $record): string => "Dossiê — {$record->display_name}")
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (LegalInstrument $record): View => view(
                'filament.resources.emissions.relation-managers.legal-instrument-dossier',
                ['instrument' => $record->load('documents.document')],
            ));
    }

    protected function makeHistoryAction(): Action
    {
        return Action::make('legal_history')
            ->label('Histórico')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading(fn (LegalInstrument $record): string => "Histórico jurídico — {$record->display_name}")
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (LegalInstrument $record): View => view(
                'filament.resources.emissions.relation-managers.legal-instrument-history',
                ['instrument' => $record->load(['events.instrumentDocument.document', 'events.recordedBy'])],
            ));
    }

    /**
     * Anexa um documento já existente na emissão ao dossiê e enfileira a leitura.
     *
     * Não há upload aqui de propósito: o documento entra pelo acervo da emissão,
     * que já aplica varredura de malware, storage privado e policies (§39).
     */
    protected function makeAttachDocumentAction(): Action
    {
        return Action::make('attach_document')
            ->label('Anexar documento')
            ->icon('heroicon-o-paper-clip')
            ->color('warning')
            ->authorize(fn (): bool => $this->userCan(AccessPermission::LegalInstrumentsAttachDocument))
            ->modalHeading('Anexar documento ao dossiê')
            ->modalDescription('O documento é lido em segundo plano e as informações encontradas ficam pendentes de revisão. Nada é alterado automaticamente.')
            ->modalSubmitActionLabel('Anexar e processar')
            ->form([
                Select::make('document_id')
                    ->label('Documento')
                    ->options(fn (): array => $this->availableDocumentOptions())
                    ->searchable()
                    ->required()
                    ->helperText('Lista os documentos da operação vinculados a esta emissão.'),
                Select::make('role')
                    ->label('Papel no dossiê')
                    ->options(LegalInstrumentDocumentRole::options())
                    ->default(LegalInstrumentDocumentRole::Amendment->value)
                    ->required()
                    ->live(),
                TextInput::make('sequence')
                    ->label('Número do aditamento')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Ex: 3 para o 3º Aditamento.')
                    ->visible(fn ($get): bool => $get('role') === LegalInstrumentDocumentRole::Amendment->value),
                DatePicker::make('document_date')
                    ->label('Data do documento')
                    ->required()
                    ->helperText('É esta data que define a ordem da cadeia e a vigência das alterações.'),
            ])
            ->action(function (LegalInstrument $record, array $data): void {
                $exists = $record->documents()
                    ->where('document_id', $data['document_id'])
                    ->exists();

                if ($exists) {
                    Notification::make()
                        ->title('Documento já está no dossiê')
                        ->warning()
                        ->send();

                    return;
                }

                /** @var LegalInstrumentDocument $instrumentDocument */
                $instrumentDocument = $record->documents()->create([
                    'document_id' => $data['document_id'],
                    'role' => $data['role'],
                    'sequence' => $data['sequence'] ?? null,
                    'document_date' => $data['document_date'],
                    'processing_status' => LegalInstrumentDocumentStatus::Pending,
                    'message' => 'Aguardando processamento...',
                    'added_by' => auth()->id(),
                ]);

                activity('legal_instrument_documents')
                    ->causedBy(auth()->user())
                    ->performedOn($instrumentDocument)
                    ->event('document_attached')
                    ->withProperties([
                        'legal_instrument_id' => $record->getKey(),
                        'document_id' => $data['document_id'],
                        'role' => $data['role'],
                    ])
                    ->log('Documento associado ao dossiê');

                ProcessLegalInstrumentDocumentJob::dispatch($instrumentDocument->getKey());

                Notification::make()
                    ->title('Documento anexado ao dossiê')
                    ->body('A leitura foi iniciada. As alterações encontradas aparecerão em "Alterações Detectadas".')
                    ->info()
                    ->send();
            });
    }

    /**
     * @return array<int, string>
     */
    protected function availableDocumentOptions(): array
    {
        return $this->getOwnerRecord()
            ->guaranteeSourceDocuments()
            ->get()
            ->mapWithKeys(fn (Document $document): array => [$document->id => $document->title])
            ->all();
    }

    protected function dossierSummary(LegalInstrument $instrument): string
    {
        $documents = $instrument->documents;

        if ($documents->isEmpty()) {
            return 'Nenhum documento';
        }

        $amendments = $documents
            ->filter(fn (LegalInstrumentDocument $document): bool => $document->role === LegalInstrumentDocumentRole::Amendment)
            ->count();

        $summary = $documents->count().' documento(s)';

        return $amendments > 0 ? $summary." · {$amendments} aditamento(s)" : $summary;
    }

    protected function hasProcessingDocuments(): bool
    {
        return LegalInstrumentDocument::query()
            ->whereIn('legal_instrument_id', $this->getOwnerRecord()->legalInstruments()->select('id'))
            ->whereIn('processing_status', [
                LegalInstrumentDocumentStatus::Pending->value,
                LegalInstrumentDocumentStatus::Processing->value,
            ])
            ->exists();
    }

    protected function userCan(AccessPermission $permission): bool
    {
        return auth()->user()?->can($permission->value) ?? false;
    }
}
