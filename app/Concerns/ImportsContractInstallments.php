<?php

namespace App\Concerns;

use App\Actions\ContractInstallments\AnalyzeContractInstallmentSpreadsheet;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetAnalysis;
use App\Actions\ContractInstallments\ImportContractInstallmentsFromSpreadsheet;
use App\Enums\ReconciliationOutcome;
use App\Models\ImportRun;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
 */
trait ImportsContractInstallments
{
    /**
     * Maximum number of rows rendered on the preview table.
     */
    private const INSTALLMENT_PREVIEW_LIMIT = 50;

    /**
     * Memoized analysis, so moving through the wizard does not re-read the file
     * on every render.
     *
     * @var array{path: string, contract: int|null, analysis: ContractInstallmentSpreadsheetAnalysis}|null
     */
    private ?array $memoizedInstallmentAnalysis = null;

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
            ->steps([
                Step::make('Arquivo')
                    ->description('Envie a posição completa da carteira')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Planilha de Parcelas (.xlsx)')
                            ->disk('local')
                            ->directory('imports/contract-installments')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                                'application/csv',
                            ])
                            ->required()
                            ->live()
                            ->helperText($helperText),
                    ]),

                Step::make('Conferência')
                    ->description('Novos, alterados e sem alteração')
                    ->schema([
                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get): Htmlable => $this->renderInstallmentPreview($get('file'), $contractId)),
                    ]),
            ])
            ->action(function (array $data) use ($contractId): void {
                $path = $this->resolveInstallmentPath($data['file'] ?? null);
                $analysis = $this->analyzeInstallments($path, $contractId);

                if (($analysis === null) || ! $analysis->canImport()) {
                    Notification::make()
                        ->danger()
                        ->title('Importação não realizada.')
                        ->body('Corrija as inconsistências apontadas na conferência e envie a planilha novamente.')
                        ->persistent()
                        ->send();

                    return;
                }

                $result = app(ImportContractInstallmentsFromSpreadsheet::class)->handle($analysis);

                $run = ImportRun::query()->create([
                    'type' => ImportRun::TYPE_CONTRACT_INSTALLMENTS,
                    'file_name' => basename((string) $path),
                    'checksum' => is_file((string) $path) ? hash_file('sha256', (string) $path) : null,
                    'user_id' => auth()->id(),
                    'contract_id' => $contractId,
                    'records_analyzed' => $analysis->totalLines(),
                    'records_created' => $result['created'],
                    'records_updated' => $result['updated'],
                    'records_unchanged' => $result['unchanged'],
                    'records_critical' => $analysis->criticalUpdateCount(),
                ]);

                activity('importacao-parcelas')
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'arquivo' => basename((string) $path),
                        'parcelas_cadastradas' => $result['created'],
                        'parcelas_atualizadas' => $result['updated'],
                        'parcelas_sem_alteracao' => $result['unchanged'],
                        'contratos_envolvidos' => $result['contracts'],
                        'contrato_id' => $contractId,
                        'importacao_id' => $run->getKey(),
                    ])
                    ->log('Conciliação de parcelas concluída.');

                Notification::make()
                    ->success()
                    ->title('Posição processada com sucesso.')
                    ->body(sprintf(
                        '%d parcela(s) adicionada(s), %d atualizada(s), %d sem alteração. Contratos envolvidos: %d.',
                        $result['created'],
                        $result['updated'],
                        $result['unchanged'],
                        $result['contracts'],
                    ))
                    ->persistent()
                    ->send();
            });
    }

    private function renderInstallmentPreview(mixed $file, ?int $contractId): Htmlable
    {
        $analysis = $this->analyzeInstallments($this->resolveInstallmentPath($file), $contractId);

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
            'Conflitos: <b>'.($analysis->conflictCount() + $analysis->errorCount() + $analysis->duplicatedInFileCount()).'</b>',
        ];

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
     * did not move are counted, and only a sample is rendered. A monthly file is
     * mostly unchanged rows, and putting ten thousand of them on screen would
     * cost more than it tells anyone.
     */
    private function renderInstallmentTable(ContractInstallmentSpreadsheetAnalysis $analysis): string
    {
        $rows = $analysis->previewRows();
        $unchanged = $analysis->unchangedCount();

        $renderedRows = $rows->take(self::INSTALLMENT_PREVIEW_LIMIT)->map(function (array $row): string {
            $outcome = $row['outcome'];
            $detail = filled($row['message'] ?? null) ? e((string) $row['message']) : '—';

            if ($outcome === ReconciliationOutcome::CriticalUpdate) {
                $detail = '⚠ '.$detail;
            }

            $dueDate = filled($row['due_date'] ?? null)
                ? Carbon::parse((string) $row['due_date'])->format('d/m/Y')
                : '—';

            $expectedValue = filled($row['expected_value'] ?? null)
                ? 'R$ '.MoneyFormatter::formatCurrencyForDisplay($row['expected_value'])
                : '—';

            return '<tr>'
                .'<td style="padding:.25rem .5rem;">'.$row['line'].'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) ($row['contract_code'] ?? '—')).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) ($row['number'] ?? '—')).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($dueDate).'</td>'
                .'<td style="padding:.25rem .5rem;text-align:right;">'.e($expectedValue).'</td>'
                .'<td style="padding:.25rem .5rem;"><b>'.e($outcome->label()).'</b></td>'
                .'<td style="padding:.25rem .5rem;">'.$detail.'</td>'
                .'</tr>';
        })->implode('');

        $notes = [];

        if ($rows->count() > self::INSTALLMENT_PREVIEW_LIMIT) {
            $notes[] = 'Exibindo as primeiras '.self::INSTALLMENT_PREVIEW_LIMIT.' de '.$rows->count().' linhas, em ordem de prioridade: conflitos, alterações críticas, atualizações, novas e por último as sem alteração.';
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
            .'<th style="text-align:left;padding:.25rem .5rem;">Resultado</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Diferenças</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'
            .($notes === [] ? '' : '<p>'.implode(' ', $notes).'</p>');
    }

    private function analyzeInstallments(?string $path, ?int $contractId): ?ContractInstallmentSpreadsheetAnalysis
    {
        if (blank($path) || ! is_file($path)) {
            return null;
        }

        if ((($this->memoizedInstallmentAnalysis['path'] ?? null) === $path)
            && (($this->memoizedInstallmentAnalysis['contract'] ?? null) === $contractId)) {
            return $this->memoizedInstallmentAnalysis['analysis'];
        }

        $analysis = app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path, $contractId);

        $this->memoizedInstallmentAnalysis = [
            'path' => $path,
            'contract' => $contractId,
            'analysis' => $analysis,
        ];

        return $analysis;
    }

    /**
     * The upload state is a temporary file while the wizard is open and a stored
     * path once the step is dehydrated.
     */
    private function resolveInstallmentPath(mixed $file): ?string
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
