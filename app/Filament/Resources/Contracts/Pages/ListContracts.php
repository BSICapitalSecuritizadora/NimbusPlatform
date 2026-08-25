<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Contracts\AnalyzeContractSpreadsheet;
use App\Actions\Contracts\ContractSpreadsheetAnalysis;
use App\Actions\Contracts\ImportContractsFromSpreadsheet;
use App\Enums\ReconciliationOutcome;
use App\Exceptions\ContractImportConcurrencyException;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Activitylog\LogBatch;

class ListContracts extends ListRecords
{
    /**
     * Maximum number of rows rendered on the preview table.
     */
    private const PREVIEW_LIMIT = 50;

    protected static string $resource = ContractResource::class;

    protected static ?string $title = 'Contratos';

    /**
     * Memoized analysis, so moving through the wizard does not re-read the file
     * on every render.
     *
     * @var array{path: string, analysis: ContractSpreadsheetAnalysis}|null
     */
    private ?array $memoizedAnalysis = null;

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
                ->icon('heroicon-o-plus'),
        ];
    }

    private function importAction(): Action
    {
        return Action::make('importContracts')
            ->label('Importar Contratos')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->modalHeading('Importar Contratos')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Confirmar')
            ->visible(fn (): bool => ContractResource::canCreate())
            ->steps([
                Step::make('Arquivo')
                    ->description('Envie a posição completa da carteira')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Planilha de Contratos (.xlsx)')
                            ->disk('local')
                            ->directory('imports/contracts')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                                'application/csv',
                            ])
                            ->required()
                            ->live()
                            /**
                             * The upload is stored under a generated name, so
                             * the name the operator recognises -- the one that
                             * has to show up in the import history months later
                             * -- is kept aside here.
                             */
                            ->storeFileNamesIn('original_file_name')
                            ->helperText('Envie a planilha completa da carteira. Contratos novos são cadastrados, os que mudaram são atualizados e os que já estão iguais são ignorados. Emissões, empreendimentos, unidades e clientes precisam já existir no sistema.'),
                    ]),

                Step::make('Conferência')
                    ->description('Novos, alterados e sem alteração')
                    ->schema([
                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get): Htmlable => $this->renderPreview($get('file'))),
                    ]),
            ])
            ->action(function (array $data): void {
                $analysis = $this->analyze($this->resolvePath($data['file'] ?? null));

                if (($analysis === null) || ! $analysis->canImport()) {
                    Notification::make()
                        ->danger()
                        ->title('Importação não realizada.')
                        ->body('Corrija as inconsistências apontadas na conferência e envie a planilha novamente.')
                        ->persistent()
                        ->send();

                    return;
                }

                /**
                 * The position may have moved between the conference and this
                 * click. Nothing was written when that happens -- the check runs
                 * inside the transaction, before the first statement -- so the
                 * run is not recorded either.
                 *
                 * Everything the confirmation writes happens inside one activity
                 * log batch: each contract saved through the model stamps the
                 * batch uuid on its own activity, which is what later answers
                 * "what did this run change" without confusing it with a manual
                 * edit made afterwards on the same contract.
                 *
                 * The batch wraps the transaction, never the other way round:
                 * `withinBatch()` closes it in a `finally`, so a failed import
                 * rolls back its writes -- activities included, they are written
                 * by the same transaction -- and leaves no batch open for the
                 * next execution to fall into.
                 */
                try {
                    [$result, $run] = app(LogBatch::class)->withinBatch(function (?string $batchUuid) use ($analysis, $data): array {
                        $result = app(ImportContractsFromSpreadsheet::class)->handle($analysis);

                        $path = $this->resolvePath($data['file'] ?? null);

                        $run = ImportRun::query()->create([
                            'type' => ImportRun::TYPE_CONTRACTS,
                            'file_name' => $this->resolveFileName($data, $path),
                            'checksum' => is_file((string) $path) ? hash_file('sha256', (string) $path) : null,
                            'activity_batch_uuid' => $batchUuid,
                            'user_id' => auth()->id(),
                            'records_analyzed' => $analysis->totalLines(),
                            'records_created' => $result['created'],
                            'records_updated' => $result['updated'],
                            'records_unchanged' => $result['unchanged'],
                            'records_critical' => $analysis->criticalUpdateCount(),
                        ]);

                        /**
                         * Part of the same batch, deliberately: it is the entry
                         * that describes the execution itself. It carries no
                         * subject, which is how the change listing tells it
                         * apart from the individual records.
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
                            ])
                            ->log('Conciliação de contratos concluída.');

                        return [$result, $run];
                    });
                } catch (ContractImportConcurrencyException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Importação não realizada.')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

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
                            ->url(ImportRunResource::getUrl('view', ['record' => $run])),
                    ]);
                }

                $notification->send();
            });
    }

    private function renderPreview(mixed $file): Htmlable
    {
        $analysis = $this->analyze($this->resolvePath($file));

        if ($analysis === null) {
            return new HtmlString('<p class="fi-color-danger">Não foi possível ler a planilha enviada.</p>');
        }

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

        if ($analysis->emptyLineCount() > 0) {
            $lines[] = 'Linhas vazias ignoradas: <b>'.$analysis->emptyLineCount().'</b>';
        }

        $verdict = match (true) {
            ! $analysis->canImport() => '<p class="fi-color-danger"><b>Corrija as inconsistências antes de confirmar. A importação só é liberada quando nenhuma linha estiver em conflito.</b></p>',
            $analysis->writeCount() === 0 => '<p class="fi-color-gray"><b>Nada a atualizar: a posição da planilha já é a posição registrada.</b></p>',
            $analysis->hasCriticalUpdates() => '<p class="fi-color-warning"><b>Há alterações críticas nesta planilha. Revise as linhas destacadas antes de confirmar.</b></p>',
            default => '<p class="fi-color-success"><b>Planilha pronta: '.$analysis->writeCount().' registro(s) serão gravados.</b></p>',
        };

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

            if ($outcome === ReconciliationOutcome::CriticalUpdate) {
                $detail = '⚠ '.$detail;
            }

            return '<tr>'
                .'<td style="padding:.25rem .5rem;">'.$row['line'].'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['construction']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['unit_label']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) ($row['client_label'] ?? '—')).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['code']).'</td>'
                .'<td style="padding:.25rem .5rem;"><b>'.e($outcome->label()).'</b></td>'
                .'<td style="padding:.25rem .5rem;">'.$detail.'</td>'
                .'</tr>';
        })->implode('');

        $notes = [];

        if ($rows->count() > self::PREVIEW_LIMIT) {
            $notes[] = 'Exibindo as primeiras '.self::PREVIEW_LIMIT.' de '.$rows->count().' linhas, em ordem de prioridade: conflitos, alterações críticas, atualizações, novos e por último os sem alteração.';
        }

        if ($unchanged > 0) {
            $notes[] = '<b>'.$unchanged.'</b> linha(s) já estão idênticas ao que está cadastrado e não serão gravadas.';
        }

        return '<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Linha</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Empreendimento</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Unidade</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Cliente</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Contrato</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Resultado</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Diferenças</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'
            .($notes === [] ? '' : '<p>'.implode(' ', $notes).'</p>');
    }

    private function analyze(?string $path): ?ContractSpreadsheetAnalysis
    {
        if (blank($path) || ! is_file($path)) {
            return null;
        }

        if (($this->memoizedAnalysis['path'] ?? null) === $path) {
            return $this->memoizedAnalysis['analysis'];
        }

        $analysis = app(AnalyzeContractSpreadsheet::class)->handle($path);

        $this->memoizedAnalysis = ['path' => $path, 'analysis' => $analysis];

        return $analysis;
    }

    /**
     * The upload state is a temporary file while the wizard is open and a stored
     * path once the step is dehydrated.
     */
    /**
     * The name to record in the history: the one the operator uploaded, falling
     * back to the stored name when the upload came in already saved.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveFileName(array $data, ?string $path): string
    {
        $original = $data['original_file_name'] ?? null;

        if (is_string($original) && ($original !== '')) {
            return $original;
        }

        return basename((string) $path);
    }

    private function resolvePath(mixed $file): ?string
    {
        if (is_array($file)) {
            $file = collect($file)->first();
        }

        if ($file instanceof TemporaryUploadedFile) {
            return $file->getRealPath();
        }

        if (! is_string($file) || ($file === '')) {
            return null;
        }

        return Storage::disk('local')->path($file);
    }
}
