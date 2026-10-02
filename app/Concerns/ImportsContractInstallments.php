<?php

namespace App\Concerns;

use App\Actions\ContractInstallments\AbsentInstallmentCancellation;
use App\Actions\ContractInstallments\AnalyzeContractInstallmentSpreadsheet;
use App\Actions\ContractInstallments\ContractInstallmentAbsenceReport;
use App\Actions\ContractInstallments\ContractInstallmentImportResult;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetAnalysis;
use App\Actions\ContractInstallments\ImportContractInstallmentsFromSpreadsheet;
use App\Enums\AccessPermission;
use App\Enums\ReconciliationOutcome;
use App\Exceptions\ImportConferenceOutdatedException;
use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use App\Rules\XlsxSpreadsheetFile;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Support\ActivityLog\LogBatch;
use App\Support\BusinessTime;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Imports\ImportConferenceStore;
use App\Support\Imports\ImportRunDraft;
use App\Support\Imports\ImportSpreadsheetSource;
use App\Support\SalesBoards\SourceEntryCompetenceNotice;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The spreadsheet import of installments, shared by the global listing and by
 * the schedule embedded in a contract.
 *
 * Both entry points read the very same file layout. The difference is the scope:
 * opened from inside a contract, the analysis is told which contract it may
 * touch, and a row pointing anywhere else is reported instead of being silently
 * redirected.
 *
 * The flow is the one every import in the platform uses -- upload, validation,
 * preview, confirmation, persistence -- and it never completes halfway: one bad
 * row among five hundred imports nothing.
 *
 * What it costs, per import: the upload reads the file once and keeps the
 * conference in {@see ImportConferenceStore}; moving to the next step reads it
 * no more; confirming reads it once, writing as it goes, and writes only if the
 * conference it recomputes is the one the operator saw.
 */
trait ImportsContractInstallments
{
    /**
     * Maximum number of rows rendered on the preview table.
     */
    private const INSTALLMENT_PREVIEW_LIMIT = ContractInstallmentSpreadsheetAnalysis::PREVIEW_LIMIT;

    /**
     * Maximum number of rows rendered on the list of rows to fix. Past it, the
     * file usually carries one systematic mistake repeated line after line.
     */
    private const INSTALLMENT_PROBLEM_LIMIT = ContractInstallmentSpreadsheetAnalysis::BLOCKING_LIMIT;

    /**
     * Where the confirmed spreadsheet is archived, on the private disk.
     */
    private const INSTALLMENT_ARCHIVE_DIRECTORY = 'imports/contract-installments';

    /**
     * The conference already hydrated in this request, by store key. Private, so
     * Livewire never ships it to the browser: it lasts one request.
     *
     * @var array<string, ContractInstallmentSpreadsheetAnalysis>
     */
    private array $installmentConferences = [];

    /**
     * O aviso de competência registrada para a data do cancelamento das
     * ausentes, por obras e data, nesta requisição.
     *
     * @var array<string, string|null>
     */
    private array $absentCancellationNotices = [];

    protected function installmentTemplateAction(): Action
    {
        return Action::make('downloadInstallmentTemplate')
            ->label('Baixar Modelo')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->tooltip('Baixar a planilha padrão de cadastro de parcelas')
            ->url(fn (): string => route('admin.contract-installments.template.download'));
    }

    /**
     * A importação concilia o cronograma: cadastra o que é novo e atualiza o que
     * mudou. Por isso exige criar **e** editar parcelas
     * ({@see ContractInstallmentResource::canImport()}), e a regra mora aqui,
     * na própria ação, e não em quem a chama: as duas telas que a oferecem -- a
     * lista e o contrato -- não têm como esquecê-la, e o `visible()` é
     * conferido de novo no servidor quando a ação monta e quando executa.
     *
     * @param  int|null  $contractId  restricts the file to a single contract when
     *                                the import is opened from its page
     */
    protected function installmentImportAction(?int $contractId = null): Action
    {
        $helperText = $contractId === null
            ? 'Envie a planilha completa da carteira. Parcelas novas são cadastradas, as que mudaram são atualizadas e as que já estão iguais são ignoradas. Emissões, empreendimentos e contratos precisam já existir no sistema.'
            : 'Envie a planilha completa deste contrato. Parcelas novas são cadastradas, as que mudaram são atualizadas e as que já estão iguais são ignoradas. Somente as linhas deste contrato serão aceitas.';

        return Action::make('importContractInstallments')
            ->label('Importar Parcelas')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->modalHeading('Importar Parcelas')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Confirmar')
            ->visible(fn (): bool => ContractInstallmentResource::canImport())
            ->steps([
                Step::make('Arquivo')
                    ->description('Envie a posição completa da carteira')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Planilha de Parcelas (.xlsx)')
                            ->disk('local')
                            /**
                             * The upload stays the temporary file Livewire
                             * signed, from the conference to the confirmation:
                             * the same bytes are read on both, and nothing is
                             * copied while the wizard is open. The confirmation
                             * archives it.
                             */
                            ->storeFiles(false)
                            ->acceptedFileTypes((array) config('uploads.spreadsheet_import.allowed_mimes', []))
                            ->rules([new XlsxSpreadsheetFile])
                            ->validationMessages(['mimetypes' => XlsxSpreadsheetFile::MESSAGE])
                            ->required()
                            ->live()
                            ->helperText($helperText),
                    ]),

                Step::make('Conferência')
                    ->description('Novos, alterados e sem alteração')
                    ->schema([
                        /**
                         * One bad row blocks the whole file, so the rows to fix
                         * are listed apart, before anything else.
                         */
                        Callout::make(fn (Get $get): string => 'Linhas a corrigir na planilha: '.$this->installmentAnalysisFor($get('file'), $contractId)?->blockingCount())
                            ->key('installmentProblems')
                            ->danger()
                            ->description('Corrija estas linhas no arquivo e envie-o novamente na etapa Arquivo.')
                            ->footer([
                                Html::make(fn (Get $get): Htmlable => new HtmlString(
                                    $this->renderInstallmentProblems($this->installmentAnalysisFor($get('file'), $contractId)),
                                )),
                            ])
                            ->visible(fn (Get $get): bool => ($this->installmentAnalysisFor($get('file'), $contractId)?->blockingCount() ?? 0) > 0),

                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get): Htmlable => $this->renderInstallmentPreview($get('file'), $contractId)),

                        Placeholder::make('installmentAbsences')
                            ->hiddenLabel()
                            ->content(fn (Get $get): Htmlable => new HtmlString(
                                $this->renderInstallmentAbsences($this->installmentAnalysisFor($get('file'), $contractId)),
                            ))
                            ->visible(fn (Get $get): bool => ($this->installmentAnalysisFor($get('file'), $contractId)?->absentCount() ?? 0) > 0),

                        /**
                         * Off by default, offered only when there is something
                         * to cancel and only to whoever may edit installments --
                         * the confirmation checks the permission again. A file
                         * split in parts would otherwise cancel real debt.
                         */
                        Toggle::make('cancel_absent_open')
                            ->label(fn (Get $get): string => sprintf(
                                'Registrar o cancelamento das %d parcela(s) em aberto ausentes da planilha',
                                $this->installmentAnalysisFor($get('file'), $contractId)?->absentOpenCount() ?? 0,
                            ))
                            ->helperText('As parcelas pagas, as parcialmente pagas e as já canceladas nunca são alteradas.')
                            ->default(false)
                            ->live()
                            ->visible(fn (Get $get): bool => $this->canCancelAbsentInstallments()
                                && (($this->installmentAnalysisFor($get('file'), $contractId)?->absentOpenCount() ?? 0) > 0)),

                        DatePicker::make('absent_cancellation_date')
                            ->label('Data do cancelamento')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            /**
                             * A Carbon instance at midnight, never a text date:
                             * the picker would fill the current time into a text
                             * default and the day bounds below would disagree.
                             */
                            ->default(fn (): CarbonImmutable => CarbonImmutable::parse(BusinessTime::dateString()))
                            ->minDate(SpreadsheetDate::MINIMUM_YEAR.'-01-01')
                            ->maxDate(fn (): string => BusinessTime::dateString())
                            ->required(fn (Get $get): bool => (bool) $get('cancel_absent_open'))
                            ->visible(fn (Get $get): bool => (bool) $get('cancel_absent_open') && $this->canCancelAbsentInstallments())
                            /**
                             * A data decide o aviso de competência registrada
                             * logo abaixo, refeito a cada escolha.
                             */
                            ->live()
                            ->validationMessages([
                                'required' => 'Informe a data do cancelamento.',
                                'after_or_equal' => 'A data do cancelamento não pode ser anterior a 01/01/'.SpreadsheetDate::MINIMUM_YEAR.'.',
                                'before_or_equal' => 'A data do cancelamento não pode ser futura.',
                            ]),

                        /**
                         * O mesmo aviso que o formulário da parcela dá para um
                         * cancelamento digitado à mão: só avisa, nunca bloqueia.
                         * A data escolhida pode cair em competência já publicada
                         * -- o fato entra como extemporâneo na seguinte -- ou
                         * registrada à mão, que precisa ser revista em "Nova
                         * Atualização". O título não distingue as duas: o texto
                         * de cada obra diz o caminho.
                         */
                        Callout::make('Data em competência já registrada no Quadro de Vendas')
                            ->key('absentCancellationNotice')
                            ->warning()
                            ->description(fn (Get $get): ?string => $this->absentCancellationNotice($get, $contractId))
                            ->visible(fn (Get $get): bool => (bool) $get('cancel_absent_open')
                                && $this->canCancelAbsentInstallments()
                                && ($this->absentCancellationNotice($get, $contractId) !== null)),

                        Textarea::make('absent_cancellation_reason')
                            ->label('Motivo do cancelamento')
                            ->rows(2)
                            ->minLength(10)
                            ->maxLength(1000)
                            ->placeholder('Renegociação com novo cronograma, parcelas substituídas...')
                            ->required(fn (Get $get): bool => (bool) $get('cancel_absent_open'))
                            ->visible(fn (Get $get): bool => (bool) $get('cancel_absent_open') && $this->canCancelAbsentInstallments())
                            ->validationMessages([
                                'required' => 'Informe o motivo do cancelamento.',
                                'min' => 'Descreva o motivo com pelo menos 10 caracteres.',
                                'max' => 'O motivo pode ter no máximo 1000 caracteres.',
                            ]),
                    ]),
            ])
            ->action(function (array $data, Action $action) use ($contractId): void {
                $this->confirmInstallmentImport($data, $action, $contractId);
            });
    }

    /**
     * The confirmation, in the order that keeps it honest:
     *
     * 1. the conference the operator saw is read back -- if it expired, it is
     *    recomputed and shown again instead of being confirmed unseen;
     * 2. a conference with rows to fix imports nothing, without reading the file;
     * 3. the spreadsheet is archived and imported inside one activity batch; the
     *    import writes only if the conference it recomputes has the same digest;
     * 4. the temporary upload is discarded.
     *
     * @param  array<string, mixed>  $data
     */
    private function confirmInstallmentImport(array $data, Action $action, ?int $contractId): void
    {
        $source = ImportSpreadsheetSource::fromState($data['file'] ?? null);

        if ($source === null) {
            $this->notifyInstallmentImportRefused();

            return;
        }

        $store = app(ImportConferenceStore::class);
        $key = $this->installmentConferenceKey($source, $contractId);

        $stored = $store->get($key);

        if (! $store->isUnavailable($key) && (($stored === null) || $store->wasComputedInThisRequest($key))) {
            $this->refreshInstallmentConference($store, $key, $source, $contractId);

            /**
             * Refeita e guardada, a conferência volta ao operador. Se o cache
             * recusou guardá-la, mostrá-la de novo não adiantaria: o próximo
             * Confirmar também não a encontraria, e o assistente ficaria em
             * "Conferência refeita." para sempre.
             */
            if (! $store->isUnavailable($key)) {
                Notification::make()
                    ->warning()
                    ->title('Conferência refeita.')
                    ->body('A conferência anterior expirou e foi recalculada com a posição atual. Revise-a e confirme novamente.')
                    ->persistent()
                    ->send();

                $action->halt();
            }

            $stored = $store->get($key);
        }

        /**
         * Com o cache indisponível -- na leitura ou na gravação -- a conferência
         * não pode ser lida de volta: a importação segue sem a guarda, como antes
         * de ela existir, e o store já deixou o aviso técnico no log.
         */
        $guarded = ! $store->isUnavailable($key);

        $conference = ContractInstallmentSpreadsheetAnalysis::fromArray($stored ?? $this->computeInstallmentConference($source, $contractId)->toArray());

        if (! $conference->canImport()) {
            $this->notifyInstallmentImportRefused();

            return;
        }

        $cancellation = $this->absentCancellationFrom($data, $conference);
        $archivedPath = $source->archive(self::INSTALLMENT_ARCHIVE_DIRECTORY);

        try {
            /**
             * Everything the confirmation writes happens inside one activity
             * log batch: each installment saved through the model stamps the
             * batch uuid on its own activity, which is what later answers
             * "what did this run change" without confusing it with a manual
             * edit made afterwards on the same installment.
             *
             * The batch wraps the transaction, never the other way round:
             * `withinBatch()` closes it in a `finally`, so a failed import
             * rolls back its writes -- activities included, they are written
             * by the same transaction -- and leaves no batch open for the
             * next execution to fall into.
             */
            $result = app(LogBatch::class)->withinBatch(fn (): ContractInstallmentImportResult => $source->read(
                function (string $path) use ($source, $contractId, $archivedPath, $guarded, $conference, $cancellation): ContractInstallmentImportResult {
                    $result = app(ImportContractInstallmentsFromSpreadsheet::class)->handle(
                        $path,
                        $contractId,
                        new ImportRunDraft(
                            type: ImportRun::TYPE_CONTRACT_INSTALLMENTS,
                            fileName: $source->originalName(),
                            checksum: $source->checksum(),
                            filePath: $archivedPath,
                            userId: self::installmentImportUserId(),
                            contractId: $contractId,
                        ),
                        $guarded ? $conference->digest() : null,
                        $cancellation,
                    );

                    $this->logInstallmentImport($result, $contractId);

                    return $result;
                },
            ));
        } catch (ImportConferenceOutdatedException $exception) {
            ImportSpreadsheetSource::forgetArchive($archivedPath);

            $store->put($key, $exception->freshSummary);
            $this->installmentConferences = [];

            Notification::make()
                ->warning()
                ->title('Conferência desatualizada.')
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            $action->halt();

            return;
        } catch (Throwable $exception) {
            ImportSpreadsheetSource::forgetArchive($archivedPath);

            throw $exception;
        }

        $store->forget($key);
        $source->discard();

        $this->notifyInstallmentImportCompleted($result);
    }

    /**
     * The conference of the file, kept in the store between the requests of the
     * wizard and hydrated once per request.
     */
    private function installmentAnalysisFor(mixed $file, ?int $contractId): ?ContractInstallmentSpreadsheetAnalysis
    {
        $source = ImportSpreadsheetSource::fromState($file);

        if ($source === null) {
            return null;
        }

        try {
            $key = $this->installmentConferenceKey($source, $contractId);

            if (isset($this->installmentConferences[$key])) {
                return $this->installmentConferences[$key];
            }

            $summary = app(ImportConferenceStore::class)->remember(
                $key,
                fn (): array => $this->computeInstallmentConference($source, $contractId)->toArray(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $this->installmentConferences[$key] = ContractInstallmentSpreadsheetAnalysis::fromArray($summary);
    }

    /**
     * One pass over the file. A file that is not a spreadsheet is answered
     * without being read.
     */
    private function computeInstallmentConference(ImportSpreadsheetSource $source, ?int $contractId): ContractInstallmentSpreadsheetAnalysis
    {
        if (! XlsxSpreadsheetFile::isSpreadsheet($source->file())) {
            return new ContractInstallmentSpreadsheetAnalysis(fileErrors: [XlsxSpreadsheetFile::MESSAGE]);
        }

        return $source->read(
            fn (string $path): ContractInstallmentSpreadsheetAnalysis => app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path, $contractId),
        );
    }

    private function refreshInstallmentConference(ImportConferenceStore $store, string $key, ImportSpreadsheetSource $source, ?int $contractId): void
    {
        if (! $store->wasComputedInThisRequest($key)) {
            $store->put($key, $this->computeInstallmentConference($source, $contractId)->toArray());
        }

        $this->installmentConferences = [];
    }

    private function installmentConferenceKey(ImportSpreadsheetSource $source, ?int $contractId): string
    {
        return ImportConferenceStore::key(ImportRun::TYPE_CONTRACT_INSTALLMENTS, self::installmentImportUserId(), $contractId, $source);
    }

    private static function installmentImportUserId(): ?int
    {
        $id = auth()->id();

        return $id === null ? null : (int) $id;
    }

    /**
     * Cancelling the absent installments edits installments, so it takes the
     * permission to edit them -- creating them is not enough. The import itself
     * already demands it ({@see ContractInstallmentResource::canImport()}); the
     * option asks again so that it never rests on the rule of the screen that
     * offers the action.
     */
    private function canCancelAbsentInstallments(): bool
    {
        return auth()->user()?->can(AccessPermission::ContractInstallmentsUpdate->value) ?? false;
    }

    /**
     * O aviso de competência registrada para a data do cancelamento das
     * ausentes, sobre as obras das parcelas em aberto que a planilha não
     * trouxe. Lembrado por requisição: o título, o texto e a visibilidade do
     * aviso perguntam a mesma coisa.
     */
    private function absentCancellationNotice(Get $get, ?int $contractId): ?string
    {
        $constructionIds = $this->installmentAnalysisFor($get('file'), $contractId)?->absentOpenConstructionIds() ?? [];
        $date = $get('absent_cancellation_date');
        $key = md5(json_encode([$constructionIds, $date instanceof DateTimeInterface ? $date->format('Y-m-d') : (string) $date], JSON_THROW_ON_ERROR));

        if (! array_key_exists($key, $this->absentCancellationNotices)) {
            $this->absentCancellationNotices[$key] = SourceEntryCompetenceNotice::forAbsentInstallmentCancellation($constructionIds, $date);
        }

        return $this->absentCancellationNotices[$key];
    }

    /**
     * The explicit decision to cancel the open installments the file left out,
     * or null. A value posted without the permission, or with nothing open to
     * cancel, is ignored.
     *
     * @param  array<string, mixed>  $data
     */
    private function absentCancellationFrom(array $data, ContractInstallmentSpreadsheetAnalysis $conference): ?AbsentInstallmentCancellation
    {
        if (! ($data['cancel_absent_open'] ?? false)
            || ! $this->canCancelAbsentInstallments()
            || ($conference->absentOpenCount() === 0)) {
            return null;
        }

        $date = $data['absent_cancellation_date'] ?? null;
        $reason = trim((string) ($data['absent_cancellation_reason'] ?? ''));

        if (blank($date) || ($reason === '')) {
            return null;
        }

        return new AbsentInstallmentCancellation(
            date: CarbonImmutable::parse((string) $date)->toDateString(),
            reason: $reason,
            userId: self::installmentImportUserId(),
        );
    }

    /**
     * Part of the same batch, deliberately: it is the entry that describes the
     * execution itself. It carries no subject, which is how the change listing
     * tells it apart from the individual records. The durable trail is the
     * {@see ImportRun} and the protected trails of the installments themselves.
     */
    private function logInstallmentImport(ContractInstallmentImportResult $result, ?int $contractId): void
    {
        activity('importacao-parcelas')
            ->causedBy(auth()->user())
            ->withProperties([
                'arquivo' => $result->run->file_name,
                'parcelas_cadastradas' => $result->created,
                'parcelas_atualizadas' => $result->updated,
                'parcelas_sem_alteracao' => $result->unchanged,
                'contratos_envolvidos' => $result->contracts,
                'avisos_por_codigo' => $result->warningsByCode,
                'parcelas_ausentes' => $result->absent,
                'parcelas_canceladas_por_ausencia' => $result->cancelled,
                'parcelas_ausentes_preservadas_por_mudanca_concorrente' => $result->cancellationSkipped,
                'contrato_id' => $contractId,
                'importacao_id' => $result->run->getKey(),
            ])
            ->log('Conciliação de parcelas concluída.');
    }

    private function notifyInstallmentImportRefused(): void
    {
        Notification::make()
            ->danger()
            ->title('Importação não realizada.')
            ->body('Corrija as inconsistências apontadas na conferência e envie a planilha novamente.')
            ->persistent()
            ->send();
    }

    private function notifyInstallmentImportCompleted(ContractInstallmentImportResult $result): void
    {
        $body = sprintf(
            '%d parcela(s) adicionada(s), %d atualizada(s), %d sem alteração. Contratos envolvidos: %d.',
            $result->created,
            $result->updated,
            $result->unchanged,
            $result->contracts,
        );

        if ($result->cancelled > 0) {
            $body .= sprintf(
                ' %d parcela(s) ausente(s) da planilha cancelada(s) em %s.',
                $result->cancelled,
                SpreadsheetDate::display($result->run->absence_cancellation_date?->toDateString()),
            );
        }

        /**
         * Pagas ou canceladas por outra tela enquanto o Confirmar gravava: o
         * cancelamento as deixou como estavam, e quem confirmou precisa saber
         * que a lista da conferência não foi cancelada inteira.
         */
        if ($result->cancellationSkipped > 0) {
            $body .= sprintf(
                ' %d parcela(s) ausente(s) não foram canceladas porque receberam pagamento ou cancelamento durante a confirmação.',
                $result->cancellationSkipped,
            );
        }

        $notification = Notification::make()
            ->success()
            ->title('Posição processada com sucesso.')
            ->body($body)
            ->persistent();

        if (ImportRunResource::canViewAny()) {
            $notification->actions([
                Action::make('viewImportRun')
                    ->label('Ver detalhes da importação')
                    ->url(ImportRunResource::getUrl('view', ['record' => $result->run])),
            ]);
        }

        $notification->send();

        /**
         * O aviso da conferência repetido depois de gravar, como os formulários
         * da parcela fazem: notificação persistente, sobre as obras das parcelas
         * de fato canceladas.
         */
        if ($result->cancelled > 0) {
            SourceEntryCompetenceNotice::notify(SourceEntryCompetenceNotice::forAbsentInstallmentCancellation(
                $result->cancelledConstructionIds,
                $result->run->absence_cancellation_date?->toDateString(),
            ));
        }
    }

    private function renderInstallmentPreview(mixed $file, ?int $contractId): Htmlable
    {
        $analysis = $this->installmentAnalysisFor($file, $contractId);

        if ($analysis === null) {
            return new HtmlString('<p class="fi-color-danger">Não foi possível ler a planilha enviada.</p>');
        }

        if ($analysis->fileErrors !== []) {
            return new HtmlString(
                '<p class="fi-color-danger"><b>'.e(implode(' ', $analysis->fileErrors)).'</b></p>'
            );
        }

        return new HtmlString(
            $this->renderInstallmentSummary($analysis).$this->renderInstallmentTable($analysis)
        );
    }

    private function renderInstallmentSummary(ContractInstallmentSpreadsheetAnalysis $analysis): string
    {
        $lines = [
            'Total analisado: <b>'.$analysis->totalLines().'</b>',
            'Novas: <b>'.$analysis->newCount().'</b>',
            'Atualizações: <b>'.$analysis->updateCount().'</b>',
            'Atualizações críticas: <b>'.$analysis->criticalUpdateCount().'</b>',
            'Sem alteração: <b>'.$analysis->unchangedCount().'</b>',
            'Conflitos: <b>'.$analysis->blockingCount().'</b>',
        ];

        if ($analysis->informativeDivergenceCount() > 0) {
            $lines[] = 'Divergências informativas: <b>'.$analysis->informativeDivergenceCount().'</b>';
        }

        if ($analysis->warningCount() > 0) {
            $lines[] = 'Com aviso: <b>'.$analysis->warningCount().'</b>';
        }

        if ($analysis->registeredCompetenceCount() > 0) {
            $lines[] = 'Alteram competência já registrada no Quadro de Vendas: <b>'.$analysis->registeredCompetenceCount().'</b>';
        }

        if (($analysis->absentCount() + $analysis->absentCancelledCount()) > 0) {
            $lines[] = sprintf(
                'Ausentes da planilha: <b>%d</b> em aberto, <b>%d</b> pagas%s (%d já canceladas, ignoradas)',
                $analysis->absentOpenCount(),
                $analysis->absentPaidCount(),
                $analysis->absentPartialCount() > 0 ? sprintf(', <b>%d</b> parcialmente pagas', $analysis->absentPartialCount()) : '',
                $analysis->absentCancelledCount(),
            );
        }

        if ($analysis->emptyLineCount() > 0) {
            $lines[] = 'Linhas vazias ignoradas: <b>'.$analysis->emptyLineCount().'</b>';
        }

        $verdict = match (true) {
            ! $analysis->canImport() => '<p class="fi-color-danger"><b>Corrija as inconsistências antes de confirmar. A importação só é liberada quando nenhuma linha estiver em conflito.</b></p>',
            ($analysis->writeCount() === 0) && ($analysis->absentOpenCount() > 0) => '<p class="fi-color-warning"><b>Nada a gravar nas linhas da planilha, mas '.$analysis->absentOpenCount().' parcela(s) em aberto cadastrada(s) não vieram nela. Confira a lista abaixo.</b></p>',
            ($analysis->writeCount() === 0) && ($analysis->absentPartialCount() > 0) => '<p class="fi-color-warning"><b>Nada a gravar nas linhas da planilha, mas '.$analysis->absentPartialCount().' parcela(s) parcialmente paga(s) cadastrada(s) não vieram nela e mantêm o contrato financiado. Confira a lista abaixo.</b></p>',
            ($analysis->writeCount() === 0) && ($analysis->absentPaidCount() > 0) => '<p class="fi-color-warning"><b>Nada a gravar nas linhas da planilha, mas '.$analysis->absentPaidCount().' parcela(s) paga(s) cadastrada(s) não vieram nela. Confira a lista abaixo.</b></p>',
            ($analysis->writeCount() === 0) && ($analysis->informativeDivergenceCount() > 0) => '<p class="fi-color-warning"><b>Nada a gravar, mas a planilha diverge do registrado em '.$analysis->informativeDivergenceCount().' linha(s). Confira as divergências informativas.</b></p>',
            $analysis->writeCount() === 0 => '<p class="fi-color-gray"><b>Nada a atualizar: a posição da planilha já é a posição registrada.</b></p>',
            $analysis->hasCriticalUpdates() => '<p class="fi-color-warning"><b>Há alterações críticas nesta planilha. Revise as linhas destacadas antes de confirmar.</b></p>',
            default => '<p class="fi-color-success"><b>Planilha pronta: '.$analysis->writeCount().' registro(s) serão gravados.</b></p>',
        };

        /**
         * Never blocking: a value read in a doubtful way, or one far from what
         * the contract says, is written as read -- but whoever confirms sees it.
         */
        if ($analysis->warningCount() > 0) {
            $verdict .= '<p class="fi-color-warning"><b>'.$analysis->warningCount().' linha(s) têm aviso (marcadas com ⚠). Confira a leitura dos valores antes de confirmar.</b></p>';
        }

        /**
         * Never blocking: a receipt arriving after a competence was registered
         * is the ordinary course of things. But the registered position is a
         * snapshot, and it does not follow the source on its own.
         */
        if ($analysis->registeredCompetenceCount() > 0) {
            $verdict .= '<p class="fi-color-warning"><b>'.$analysis->registeredCompetenceCount().' linha(s) alteram fatos de competências já registradas no Quadro de Vendas (marcadas com ⚑). A posição registrada não muda sozinha: na competência publicada pelo ciclo, o fato entra como movimento extemporâneo na próxima competência; na registrada manualmente, revise o quadro dela.</b></p>';
        }

        return '<div class="fi-ta-text-item-label">'.implode(' &nbsp;·&nbsp; ', $lines).'</div>'.$verdict;
    }

    /**
     * Every row that stops the import, with the emission, development, contract
     * and parcela exactly as the file wrote them: that is what has to be found
     * in the spreadsheet, not what the platform would have matched them to.
     */
    private function renderInstallmentProblems(?ContractInstallmentSpreadsheetAnalysis $analysis): string
    {
        $rows = $analysis?->blockingRows() ?? collect();
        $total = $analysis?->blockingCount() ?? 0;

        $renderedRows = $rows->take(self::INSTALLMENT_PROBLEM_LIMIT)->map(fn (array $row): string => '<tr>'
            .'<td style="padding:.25rem .5rem;">'.$row['line'].'</td>'
            .'<td style="padding:.25rem .5rem;">'.e((string) ($row['emission'] ?? '—')).'</td>'
            .'<td style="padding:.25rem .5rem;">'.e((string) ($row['construction'] ?? '—')).'</td>'
            .'<td style="padding:.25rem .5rem;">'.e((string) ($row['contract_code'] ?? '—')).'</td>'
            .'<td style="padding:.25rem .5rem;">'.e((string) ($row['number'] ?? '—')).'</td>'
            .'<td style="padding:.25rem .5rem;min-width:16rem;">'.e(filled($row['message'] ?? null) ? (string) $row['message'] : $row['outcome']->label()).'</td>'
            .'</tr>')->implode('');

        $note = $total > self::INSTALLMENT_PROBLEM_LIMIT
            ? '<p>Exibindo as primeiras '.self::INSTALLMENT_PROBLEM_LIMIT.' de '.$total.' linhas a corrigir, na ordem da planilha.</p>'
            : '';

        return '<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Linha</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Emissão</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Empreendimento</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Contrato</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Parcela</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Problema</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'
            .$note;
    }

    /**
     * The rows that block the import are listed apart, above; this table holds
     * the rest, the ones that need attention first. The ones that did not move
     * are counted, and only a sample is rendered. A monthly file is mostly
     * unchanged rows, and putting ten thousand of them on screen would cost more
     * than it tells anyone.
     */
    private function renderInstallmentTable(ContractInstallmentSpreadsheetAnalysis $analysis): string
    {
        $rows = $analysis->previewRows();

        if ($rows->isEmpty()) {
            return '';
        }

        $unchanged = $analysis->unchangedCount();

        $renderedRows = $rows->take(self::INSTALLMENT_PREVIEW_LIMIT)->map(function (array $row): string {
            $outcome = $row['outcome'];
            $detail = filled($row['message'] ?? null) ? e((string) $row['message']) : '—';

            if (in_array($outcome, [ReconciliationOutcome::CriticalUpdate, ReconciliationOutcome::InformativeDivergence], true)) {
                $detail = '⚠ '.$detail;
            }

            foreach ($row['warnings'] ?? [] as $warning) {
                $detail .= '<br><span class="fi-color-warning">⚠ '.e((string) ($warning['message'] ?? '')).'</span>';
            }

            /**
             * O aviso vem da própria linha: ele diz o caminho do fato pela forma
             * como a competência foi registrada -- publicada pelo ciclo, em
             * retificação ou manual. A forma antiga fica para a conferência
             * guardada antes do aviso por linha.
             */
            $registeredWarning = $row['registered_competence_notice']
                ?? RegisteredCompetenceIndex::describe($row['registered_competences'] ?? []);

            if ($registeredWarning !== null) {
                $detail .= '<br><span class="fi-color-warning">⚑ '.e($registeredWarning).'</span>';
            }

            /**
             * Dates and amounts as the import understood them, not as the file
             * wrote them: a year read with two digits or an amount read a
             * thousand times over is caught here, before it is written.
             */
            $dueDate = SpreadsheetDate::display($row['due_date'] ?? null);
            $expectedValue = $this->formatInstallmentMoney($row['expected_value'] ?? null);
            $paymentDate = SpreadsheetDate::display($row['payment_date'] ?? null);
            $paidValue = $this->formatInstallmentMoney($row['paid_value'] ?? null);
            $discountValue = $this->formatInstallmentMoney($row['discount_value'] ?? null);

            return '<tr>'
                .'<td style="padding:.25rem .5rem;">'.$row['line'].'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) ($row['contract_code'] ?? '—')).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) ($row['number'] ?? '—')).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($dueDate).'</td>'
                .'<td style="padding:.25rem .5rem;text-align:right;white-space:nowrap;">'.e($expectedValue).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($paymentDate).'</td>'
                .'<td style="padding:.25rem .5rem;text-align:right;white-space:nowrap;">'.e($paidValue).'</td>'
                .'<td style="padding:.25rem .5rem;text-align:right;white-space:nowrap;">'.e($discountValue).'</td>'
                .'<td style="padding:.25rem .5rem;"><b>'.e($outcome->label()).'</b></td>'
                .'<td style="padding:.25rem .5rem;">'.$detail.'</td>'
                .'</tr>';
        })->implode('');

        $notes = [];

        if ($analysis->previewCandidateCount() > self::INSTALLMENT_PREVIEW_LIMIT) {
            $notes[] = 'Exibindo as primeiras '.self::INSTALLMENT_PREVIEW_LIMIT.' de '.$analysis->previewCandidateCount().' linhas, em ordem de prioridade: alterações críticas e divergências, linhas gravadas com aviso ou competência registrada, linhas sem alteração com aviso, atualizações, novas e por último as sem alteração.';
        }

        /**
         * As alterações críticas vêm sempre primeiro, mas a amostra tem um teto:
         * passando dele, quem confirma precisa saber quantas não estão na
         * tabela.
         */
        $criticalTotal = $analysis->criticalUpdateCount() + $analysis->informativeDivergenceCount();
        $criticalShown = $rows->take(self::INSTALLMENT_PREVIEW_LIMIT)
            ->filter(fn (array $row): bool => in_array($row['outcome'], [ReconciliationOutcome::CriticalUpdate, ReconciliationOutcome::InformativeDivergence], true))
            ->count();

        if ($criticalTotal > $criticalShown) {
            $notes[] = '<b>'.($criticalTotal - $criticalShown).'</b> alteração(ões) crítica(s) ou divergência(s) não cabem nesta tabela: confira-as na planilha antes de confirmar.';
        }

        if ($unchanged > 0) {
            $notes[] = '<b>'.$unchanged.'</b> linha(s) já estão idênticas ao que está cadastrado e não serão gravadas.';
        }

        return '<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Linha</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Contrato</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Parcela</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Vencimento</th>'
            .'<th style="text-align:right;padding:.25rem .5rem;">Previsto</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Pagamento</th>'
            .'<th style="text-align:right;padding:.25rem .5rem;">Pago</th>'
            .'<th style="text-align:right;padding:.25rem .5rem;">Desconto</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Resultado</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Diferenças</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'
            .($notes === [] ? '' : '<p>'.implode(' ', $notes).'</p>');
    }

    /**
     * The installments on record that the file left out, for the contracts it
     * does carry. Nothing changes in them unless the option below is marked.
     */
    private function renderInstallmentAbsences(?ContractInstallmentSpreadsheetAnalysis $analysis): string
    {
        if (($analysis === null) || ($analysis->absentCount() === 0)) {
            return '';
        }

        $rows = $analysis->absentRows();

        $renderedRows = $rows->map(fn (array $row): string => '<tr>'
            .'<td style="padding:.25rem .5rem;">'.e((string) ($row['contract'] ?? '—')).'</td>'
            .'<td style="padding:.25rem .5rem;">'.e((string) ($row['number'] ?? '—')).'</td>'
            .'<td style="padding:.25rem .5rem;">'.e(SpreadsheetDate::display($row['due_date'] ?? null)).'</td>'
            .'<td style="padding:.25rem .5rem;text-align:right;white-space:nowrap;">'.e($this->formatInstallmentMoney($row['expected_value'] ?? null)).'</td>'
            .'<td style="padding:.25rem .5rem;">'.e(self::absentSituationLabel($row)).'</td>'
            .'</tr>')->implode('');

        $note = $analysis->absentCount() > $rows->count()
            ? '<p>Exibindo as primeiras '.$rows->count().' de '.$analysis->absentCount().' parcelas ausentes, na ordem do cadastro.</p>'
            : '';

        /**
         * A partially paid installment has a receipt, so the option below never
         * touches it -- but it keeps the contract financed on the Sales Board
         * for as long as the receipt does not cover the expected value.
         */
        if ($analysis->absentPartialCount() > 0) {
            $note .= '<p class="fi-color-warning">'.$analysis->absentPartialCount().' parcela(s) parcialmente paga(s): o pago não cobre o previsto e o contrato continua financiado no Quadro de Vendas. Se houve desconto na baixa, registre o desconto concedido na parcela; se ela foi substituída por uma renegociação, cancele-a pela edição da parcela.</p>';
        }

        return '<div><p><b>Parcelas cadastradas que não vieram na planilha</b></p>'
            .'<p class="fi-color-gray">Consideradas apenas as parcelas dos contratos presentes nesta planilha. Nada muda nelas, a menos que você marque a opção abaixo.</p>'
            .'<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Contrato</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Parcela</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Vencimento</th>'
            .'<th style="text-align:right;padding:.25rem .5rem;">Previsto</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Situação</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'
            .$note.'</div>';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function absentSituationLabel(array $row): string
    {
        return match ($row['situation'] ?? null) {
            ContractInstallmentAbsenceReport::SITUATION_PAID => 'Paga em '.SpreadsheetDate::display($row['payment_date'] ?? null),
            ContractInstallmentAbsenceReport::SITUATION_PARTIAL => sprintf(
                'Parcialmente paga em %s (R$ %s de R$ %s)',
                SpreadsheetDate::display($row['payment_date'] ?? null),
                MoneyFormatter::formatCurrencyForDisplay($row['paid_value'] ?? 0),
                MoneyFormatter::formatCurrencyForDisplay($row['expected_value'] ?? 0),
            ),
            default => 'Em aberto',
        };
    }

    private function formatInstallmentMoney(mixed $value): string
    {
        return blank($value) ? '—' : 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value);
    }
}
