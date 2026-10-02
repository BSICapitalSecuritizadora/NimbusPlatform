<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Contracts\AnalyzeContractSpreadsheet;
use App\Actions\Contracts\ContractSpreadsheetAnalysis;
use App\Actions\Contracts\ImportContractsFromSpreadsheet;
use App\Enums\ReconciliationOutcome;
use App\Exceptions\ContractImportConcurrencyException;
use App\Exceptions\ImportConferenceOutdatedException;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use App\Rules\XlsxSpreadsheetFile;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Support\ActivityLog\LogBatch;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Imports\ImportConferenceStore;
use App\Support\Imports\ImportRunDraft;
use App\Support\Imports\ImportSpreadsheetSource;
use App\Support\Reconciliation\ValueComparator;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Throwable;

class ListContracts extends ListRecords
{
    /**
     * Maximum number of rows rendered on the preview table.
     */
    private const PREVIEW_LIMIT = 50;

    /**
     * Where the confirmed spreadsheet is archived, on the private disk.
     */
    private const ARCHIVE_DIRECTORY = 'imports/contracts';

    protected static string $resource = ContractResource::class;

    protected static ?string $title = 'Contratos';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-contracts-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie os contratos de compra e venda vinculados às unidades e empreendimentos.';
    }

    /**
     * The analysis of the upload, by checksum: the conference and the
     * confirmation of one request read the file once. Private, so it lasts one
     * request -- a contracts file is small enough to be analysed again on every
     * step, and what travels between the steps is only its digest.
     *
     * @var array<string, ContractSpreadsheetAnalysis>
     */
    private array $contractAnalyses = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')
                ->label('Baixar Modelo')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Baixar a planilha padrão de cadastro de contratos')
                ->url(fn (): string => route('admin.contracts.template.download')),

            $this->importAction(),

            CreateAction::make()
                ->label('Novo Contrato')
                ->icon('heroicon-o-plus')
                ->color('primary'),
        ];
    }

    private function importAction(): Action
    {
        return Action::make('importContracts')
            ->label('Importar Contratos')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading('Importar Contratos')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Confirmar')
            /**
             * A conciliação cadastra e atualiza -- inclusive a passagem para
             * distratado --, então exige criar e editar contratos.
             */
            ->visible(fn (): bool => ContractResource::canImport())
            ->steps([
                Step::make('Arquivo')
                    ->description('Envie a posição completa da carteira')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Planilha de Contratos (.xlsx)')
                            ->disk('local')
                            /**
                             * The upload stays the temporary file Livewire
                             * signed until the confirmation, which archives it:
                             * the conference and the import read the same bytes.
                             */
                            ->storeFiles(false)
                            ->acceptedFileTypes((array) config('uploads.spreadsheet_import.allowed_mimes', []))
                            ->rules([new XlsxSpreadsheetFile])
                            ->validationMessages(['mimetypes' => XlsxSpreadsheetFile::MESSAGE])
                            ->required()
                            ->live()
                            ->helperText('Envie a planilha completa da carteira. Contratos novos são cadastrados, os que mudaram são atualizados e os que já estão iguais são ignorados. Um contrato com mais de um comprador ocupa uma linha por comprador, repetindo os mesmos dados contratuais. Emissões, empreendimentos, unidades e clientes precisam já existir no sistema.'),
                    ]),

                Step::make('Conferência')
                    ->description('Novos, alterados e sem alteração')
                    ->schema([
                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get): Htmlable => $this->renderPreview($get('file'))),
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                $this->confirmContractImport($data, $action);
            });
    }

    /**
     * The confirmation analyses the file again -- a couple of thousand lines --
     * and writes only if it still says what the conference said: the digest of
     * the conference the operator saw is kept between the requests of the
     * wizard. When the position moved in between, the new conference is shown
     * instead of being applied unseen.
     *
     * @param  array<string, mixed>  $data
     */
    private function confirmContractImport(array $data, Action $action): void
    {
        $source = ImportSpreadsheetSource::fromState($data['file'] ?? null);
        $analysis = $source === null ? null : $this->analyze($source);

        if (($source === null) || ($analysis === null) || ! $analysis->canImport()) {
            $this->notifyImportRefused();

            return;
        }

        $store = app(ImportConferenceStore::class);
        $key = $this->conferenceKey($source);
        $seen = $store->get($key);

        /**
         * Com o cache indisponível -- na leitura ou na gravação -- o digest que
         * o operador viu não pode ser lido de volta, e o Confirmar segue sem a
         * guarda, como antes de ela existir. Um cache que lê mas recusa gravar
         * responderia "Conferência refeita." a todo Confirmar, para sempre: o
         * digest guardado aqui nunca seria encontrado pelo seguinte.
         */
        if (! $store->isUnavailable($key)) {
            if (($seen === null) || $store->wasComputedInThisRequest($key)) {
                $store->put($key, ['digest' => $analysis->digest()]);

                if (! $store->isUnavailable($key)) {
                    Notification::make()
                        ->warning()
                        ->title('Conferência refeita.')
                        ->body('A conferência anterior expirou e foi recalculada com a posição atual. Revise-a e confirme novamente.')
                        ->persistent()
                        ->send();

                    $action->halt();
                }
            } elseif (! hash_equals((string) ($seen['digest'] ?? ''), $analysis->digest())) {
                $store->put($key, ['digest' => $analysis->digest()]);

                Notification::make()
                    ->warning()
                    ->title('Conferência desatualizada.')
                    ->body(ImportConferenceOutdatedException::MESSAGE)
                    ->persistent()
                    ->send();

                $action->halt();
            }
        }

        $archivedPath = $source->archive(self::ARCHIVE_DIRECTORY);

        /**
         * The position may have moved between the conference and this click.
         * Nothing was written when that happens -- the check runs inside the
         * transaction, before the first statement -- so the run is not recorded
         * either.
         *
         * Everything the confirmation writes happens inside one activity log
         * batch: each contract saved through the model stamps the batch uuid on
         * its own activity, which is what later answers "what did this run
         * change" without confusing it with a manual edit made afterwards on the
         * same contract.
         *
         * The batch wraps the transaction, never the other way round:
         * `withinBatch()` closes it in a `finally`, so a failed import rolls back
         * its writes -- activities included, they are written by the same
         * transaction -- and leaves no batch open for the next execution to fall
         * into.
         */
        try {
            $result = app(LogBatch::class)->withinBatch(function () use ($analysis, $source, $archivedPath): array {
                $result = app(ImportContractsFromSpreadsheet::class)->handle($analysis, new ImportRunDraft(
                    type: ImportRun::TYPE_CONTRACTS,
                    fileName: $source->originalName(),
                    checksum: $source->checksum(),
                    filePath: $archivedPath,
                    userId: self::importUserId(),
                ));

                /** @var ImportRun $run */
                $run = $result['run'];

                /**
                 * Part of the same batch, deliberately: it is the entry that
                 * describes the execution itself. It carries no subject, which
                 * is how the change listing tells it apart from the individual
                 * records.
                 */
                activity('importacao-contratos')
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'arquivo' => $run->file_name,
                        'importacao_id' => $run->getKey(),
                        'contratos_cadastrados' => $result['created'],
                        'contratos_atualizados' => $result['updated'],
                        'contratos_sem_alteracao' => $result['unchanged'],
                        'unidades_envolvidas' => $result['units'],
                        'clientes_envolvidos' => $result['clients'],
                        'empreendimentos_envolvidos' => $result['constructions'],
                        'contratos_ausentes' => $analysis->absentContractCount(),
                        'avisos_por_codigo' => $analysis->warningsByCode(),
                    ])
                    ->log('Conciliação de contratos concluída.');

                return $result;
            });
        } catch (ContractImportConcurrencyException $exception) {
            ImportSpreadsheetSource::forgetArchive($archivedPath);

            Notification::make()
                ->danger()
                ->title('Importação não realizada.')
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            return;
        } catch (Throwable $exception) {
            ImportSpreadsheetSource::forgetArchive($archivedPath);

            throw $exception;
        }

        $store->forget($key);
        $source->discard();

        $notification = Notification::make()
            ->success()
            ->title('Posição processada com sucesso.')
            ->body(sprintf(
                '%d contrato(s) adicionado(s), %d atualizado(s), %d sem alteração.',
                $result['created'],
                $result['updated'],
                $result['unchanged'],
            ))
            ->persistent();

        if (ImportRunResource::canViewAny()) {
            $notification->actions([
                Action::make('viewImportRun')
                    ->label('Ver detalhes da importação')
                    ->url(ImportRunResource::getUrl('view', ['record' => $result['run']])),
            ]);
        }

        $notification->send();
    }

    private function notifyImportRefused(): void
    {
        Notification::make()
            ->danger()
            ->title('Importação não realizada.')
            ->body('Corrija as inconsistências apontadas na conferência e envie a planilha novamente.')
            ->persistent()
            ->send();
    }

    private function renderPreview(mixed $file): Htmlable
    {
        $source = ImportSpreadsheetSource::fromState($file);
        $analysis = $source === null ? null : $this->analyze($source);

        if ($analysis === null) {
            return new HtmlString('<p class="fi-color-danger">Não foi possível ler a planilha enviada.</p>');
        }

        /**
         * The digest of the conference on screen, kept for the confirmation. The
         * first one shown is the one that counts: a later request only reads it.
         */
        rescue(fn (): array => app(ImportConferenceStore::class)->remember(
            $this->conferenceKey($source),
            fn (): array => ['digest' => $analysis->digest()],
        ), report: false);

        if ($analysis->fileErrors !== []) {
            return new HtmlString(
                '<p class="fi-color-danger"><b>'.e(implode(' ', $analysis->fileErrors)).'</b></p>'
            );
        }

        return new HtmlString($this->renderSummary($analysis).$this->renderTable($analysis));
    }

    private function renderSummary(ContractSpreadsheetAnalysis $analysis): string
    {
        $lines = [
            'Total analisado: <b>'.$analysis->totalLines().'</b>',
            'Novos: <b>'.$analysis->newCount().'</b>',
            'Atualizações: <b>'.$analysis->updateCount().'</b>',
            'Atualizações críticas: <b>'.$analysis->criticalUpdateCount().'</b>',
            'Sem alteração: <b>'.$analysis->unchangedCount().'</b>',
            'Conflitos: <b>'.($analysis->conflictCount() + $analysis->errorCount() + $analysis->duplicatedInFileCount()).'</b>',
        ];

        if ($analysis->resaleCount() > 0) {
            $lines[] = 'Revendas na mesma unidade: <b>'.$analysis->resaleCount().'</b>';
        }

        if ($analysis->warningCount() > 0) {
            $lines[] = 'Com aviso: <b>'.$analysis->warningCount().'</b>';
        }

        if ($analysis->registeredCompetenceCount() > 0) {
            $lines[] = 'Alteram competência já registrada no Quadro de Vendas: <b>'.$analysis->registeredCompetenceCount().'</b>';
        }

        if ($analysis->absentContractCount() > 0) {
            $lines[] = 'Contratos cadastrados ausentes da planilha: <b>'.$analysis->absentContractCount().'</b>';
        }

        if ($analysis->emptyLineCount() > 0) {
            $lines[] = 'Linhas vazias ignoradas: <b>'.$analysis->emptyLineCount().'</b>';
        }

        $verdict = match (true) {
            ! $analysis->canImport() => '<p class="fi-color-danger"><b>Corrija as inconsistências antes de confirmar. A importação só é liberada quando nenhuma linha estiver em conflito.</b></p>',
            ($analysis->writeCount() === 0) && ($analysis->absentContractCount() > 0) => '<p class="fi-color-warning"><b>Nada a gravar nas linhas da planilha, mas há contratos cadastrados destes empreendimentos que não vieram nela. Confira a lista abaixo.</b></p>',
            $analysis->writeCount() === 0 => '<p class="fi-color-gray"><b>Nada a atualizar: a posição da planilha já é a posição registrada.</b></p>',
            $analysis->hasCriticalUpdates() => '<p class="fi-color-warning"><b>Há alterações críticas nesta planilha. Revise as linhas destacadas antes de confirmar.</b></p>',
            default => '<p class="fi-color-success"><b>Planilha pronta: '.$analysis->writeCount().' registro(s) serão gravados.</b></p>',
        };

        /**
         * Never blocking: a value read in a doubtful way, or one far from the
         * table, is written as read -- but whoever confirms sees it first.
         */
        if ($analysis->warningCount() > 0) {
            $verdict .= '<p class="fi-color-warning"><b>'.$analysis->warningCount().' linha(s) têm aviso (marcadas com ⚠). Confira os valores antes de confirmar.</b></p>';
        }

        /**
         * Nothing is done to the contracts the file leaves out: a contract not
         * mentioned keeps holding its unit. A distrato that never reached the
         * file is the case worth saying out loud.
         */
        if ($analysis->absentContractCount() > 0) {
            $verdict .= '<p class="fi-color-warning"><b>'.$analysis->absentContractCount().' contrato(s) cadastrado(s) destes empreendimentos não vieram na planilha. Nada muda neles; se algum foi distratado, inclua-o com o status Distratado e a data do distrato.</b></p>';
        }

        /**
         * Never blocking: correcting the source after a competence was
         * registered is legitimate. But the registered position is a snapshot
         * that guarantees and the monthly report keep reading, and it does not
         * follow the correction on its own.
         */
        if ($analysis->registeredCompetenceCount() > 0) {
            $verdict .= '<p class="fi-color-warning"><b>'.$analysis->registeredCompetenceCount().' linha(s) alteram fatos de competências já registradas no Quadro de Vendas (marcadas com ⚑). A posição registrada não muda sozinha: na competência publicada pelo ciclo, o fato entra como movimento extemporâneo na próxima competência; na registrada manualmente, revise o quadro dela.</b></p>';
        }

        return '<div class="fi-ta-text-item-label">'.implode(' &nbsp;·&nbsp; ', $lines).'</div>'.$verdict;
    }

    /**
     * Rows that need attention come first and are shown in full; the ones that
     * did not move are counted, not listed. A monthly file is mostly unchanged
     * rows.
     */
    private function renderTable(ContractSpreadsheetAnalysis $analysis): string
    {
        $rows = $analysis->previewRows();
        $unchanged = $analysis->unchangedCount();

        $renderedRows = $rows->take(self::PREVIEW_LIMIT)->map(function (array $row): string {
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

            return '<tr>'
                /**
                 * The lines of a contract with several buyers were collapsed into
                 * one entry, so the cell names all of them: "12, 13" reads back
                 * to the file the operator is holding.
                 */
                .'<td style="padding:.25rem .5rem;">'.e(implode(', ', $row['lines'] ?? [$row['line']])).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['construction']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['unit_label']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) ($row['client_label'] ?? '—')).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['code']).'</td>'
                /**
                 * The date and value as the import understood them, not as
                 * the file wrote them: a year read with two digits or an
                 * amount read a thousand times over is caught here, before
                 * it is written.
                 */
                .'<td style="padding:.25rem .5rem;">'.e(SpreadsheetDate::display($row['sale_date'] ?? null)).'</td>'
                .'<td style="padding:.25rem .5rem;text-align:right;white-space:nowrap;">'.e(ValueComparator::formatMoney($row['sale_value'] ?? null) ?? '—').'</td>'
                .'<td style="padding:.25rem .5rem;"><b>'.e($outcome->label()).'</b></td>'
                .'<td style="padding:.25rem .5rem;">'.$detail.'</td>'
                .'</tr>';
        })->implode('');

        $notes = [];

        if ($rows->count() > self::PREVIEW_LIMIT) {
            $notes[] = 'Exibindo as primeiras '.self::PREVIEW_LIMIT.' de '.$rows->count().' linhas, em ordem de prioridade: bloqueantes, alterações críticas, linhas gravadas com aviso ou competência registrada, linhas sem alteração com aviso, atualizações, novos e sem alteração.';
        }

        if ($unchanged > 0) {
            $notes[] = '<b>'.$unchanged.'</b> linha(s) já estão idênticas ao que está cadastrado e não serão gravadas.';
        }

        return '<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Linha</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Empreendimento</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Unidade</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Compradores</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Contrato</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Data da venda</th>'
            .'<th style="text-align:right;padding:.25rem .5rem;">Valor da venda</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Resultado</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Diferenças</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'
            .($notes === [] ? '' : '<p>'.implode(' ', $notes).'</p>')
            .$this->renderAbsentContracts($analysis);
    }

    /**
     * The live contracts of the developments in the file that the file does not
     * mention -- a sample, with the total.
     */
    private function renderAbsentContracts(ContractSpreadsheetAnalysis $analysis): string
    {
        if ($analysis->absentContractCount() === 0) {
            return '';
        }

        $rows = $analysis->absentContracts();

        $renderedRows = $rows->map(fn (array $contract): string => '<tr>'
            .'<td style="padding:.25rem .5rem;">'.e($contract['construction']).'</td>'
            .'<td style="padding:.25rem .5rem;">'.e($contract['unit']).'</td>'
            .'<td style="padding:.25rem .5rem;">'.e($contract['code']).'</td>'
            .'<td style="padding:.25rem .5rem;">'.e($contract['status']).'</td>'
            .'</tr>')->implode('');

        $note = $analysis->absentContractCount() > $rows->count()
            ? '<p>Exibindo os primeiros '.$rows->count().' de '.$analysis->absentContractCount().' contratos ausentes.</p>'
            : '';

        return '<div><p><b>Contratos cadastrados que não vieram na planilha</b></p>'
            .'<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Empreendimento</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Unidade</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Contrato</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Status</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'
            .$note.'</div>';
    }

    /**
     * The analysis of the upload, once per request. A file that is not a
     * spreadsheet is answered without being read.
     */
    private function analyze(ImportSpreadsheetSource $source): ?ContractSpreadsheetAnalysis
    {
        try {
            $checksum = $source->checksum();

            if (isset($this->contractAnalyses[$checksum])) {
                return $this->contractAnalyses[$checksum];
            }

            $analysis = XlsxSpreadsheetFile::isSpreadsheet($source->file())
                ? $source->read(fn (string $path): ContractSpreadsheetAnalysis => app(AnalyzeContractSpreadsheet::class)->handle($path))
                : new ContractSpreadsheetAnalysis(fileErrors: [XlsxSpreadsheetFile::MESSAGE]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $this->contractAnalyses[$checksum] = $analysis;
    }

    private function conferenceKey(ImportSpreadsheetSource $source): string
    {
        return ImportConferenceStore::key(ImportRun::TYPE_CONTRACTS, self::importUserId(), null, $source);
    }

    private static function importUserId(): ?int
    {
        $id = auth()->id();

        return $id === null ? null : (int) $id;
    }
}
