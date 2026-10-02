<?php

namespace App\Filament\Resources\ConstructionUnits\Pages;

use App\Actions\ConstructionUnits\AnalyzeConstructionUnitSpreadsheet;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetAnalysis;
use App\Actions\ConstructionUnits\ImportConstructionUnitsFromSpreadsheet;
use App\Actions\ConstructionUnitValues\AnalyzeUnitValueSpreadsheet;
use App\Actions\ConstructionUnitValues\ImportUnitValuesFromSpreadsheet;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetAnalysis;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use App\Rules\XlsxSpreadsheetFile;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Support\ActivityLog\LogBatch;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Imports\ImportRunDraft;
use App\Support\Imports\ImportSpreadsheetSource;
use App\Support\Money\IntegerMoney;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Throwable;

class ListConstructionUnits extends ListRecords
{
    /**
     * Maximum number of rows rendered on the preview table.
     */
    private const PREVIEW_LIMIT = 50;

    protected static string $resource = ConstructionUnitResource::class;

    protected static ?string $title = 'Unidades';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-construction-units-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie as unidades vinculadas aos empreendimentos das emissões.';
    }

    /**
     * Análises do envio nesta requisição, por checksum: a conferência e o
     * Confirmar de uma mesma requisição leem a planilha uma vez só. Privadas,
     * então duram uma requisição.
     *
     * @var array<string, ConstructionUnitSpreadsheetAnalysis>
     */
    private array $unitAnalyses = [];

    /**
     * @var array<string, UnitValueSpreadsheetAnalysis>
     */
    private array $valueAnalyses = [];

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('downloadTemplate')
                    ->label('Modelo de cadastro')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->tooltip('Baixar a planilha padrão de cadastro de unidades')
                    ->url(fn (): string => route('admin.construction-units.template.download')),

                Action::make('downloadValueTemplate')
                    ->label('Modelo de atualização de valores')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->tooltip('Baixar a planilha padrão de atualização de valores')
                    ->visible(fn (): bool => auth()->user()?->can('constructions.update') ?? false)
                    ->url(fn (): string => route('admin.construction-unit-values.template.download')),
            ])
                ->label('Baixar Modelo')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->button(),

            $this->importAction(),

            $this->updateValuesAction(),

            CreateAction::make()
                ->label('Nova Unidade')
                ->icon('heroicon-o-plus')
                ->color('primary'),
        ];
    }

    private function importAction(): Action
    {
        return Action::make('importUnits')
            ->label('Importar Unidades')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading('Importar Unidades')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Confirmar importação')
            ->visible(fn (): bool => ConstructionUnitResource::canCreate())
            ->steps([
                Step::make('Arquivo')
                    ->description('Selecione a planilha preenchida')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Planilha de Unidades (.xlsx)')
                            ->disk('local')
                            ->storeFiles(false)
                            ->acceptedFileTypes((array) config('uploads.spreadsheet_import.allowed_mimes', []))
                            ->rules([new XlsxSpreadsheetFile])
                            ->validationMessages(['mimetypes' => XlsxSpreadsheetFile::MESSAGE])
                            ->required()
                            ->live()
                            ->helperText('Utilize a planilha padrão. Emissões e empreendimentos precisam já existir no sistema. Unidades que deixaram de existir não saem por planilha: a Gestão registra a baixa na aba "Baixas" da unidade.'),
                    ]),

                Step::make('Conferência')
                    ->description('Revise antes de confirmar')
                    ->schema([
                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get): Htmlable => $this->renderPreview($get('file'))),
                    ]),
            ])
            ->action(function (array $data): void {
                $source = ImportSpreadsheetSource::fromState($data['file'] ?? null);
                $analysis = $source === null ? null : $this->analyze($source);

                if (($source === null) || ($analysis === null) || ! $analysis->canImport()) {
                    Notification::make()
                        ->danger()
                        ->title('Importação não realizada.')
                        ->body('Corrija as inconsistências apontadas na conferência e envie a planilha novamente.')
                        ->persistent()
                        ->send();

                    return;
                }

                /**
                 * A importação vira um registro próprio, como a de contratos e a
                 * de parcelas: arquivo arquivado, quem, quando e o que criou --
                 * as unidades criadas apontam para ele.
                 */
                $result = $this->runArchived($source, 'imports/construction-units', function (string $archivedPath) use ($analysis, $source): array {
                    $result = app(ImportConstructionUnitsFromSpreadsheet::class)->handle(
                        $analysis,
                        self::draftFor(ImportRun::TYPE_CONSTRUCTION_UNITS, $source, $archivedPath),
                    );

                    activity('importacao-unidades')
                        ->causedBy(auth()->user())
                        ->withProperties([
                            'arquivo' => $source->originalName(),
                            'importacao_id' => $result['run']?->getKey(),
                            'unidades_cadastradas' => $result['units'],
                            'empreendimentos_envolvidos' => $result['constructions'],
                            'emissoes_envolvidas' => $result['emissions'],
                        ])
                        ->log('Importação de unidades concluída.');

                    return $result;
                });

                $this->notifyCompleted(
                    'Importação concluída com sucesso.',
                    sprintf(
                        '%d unidades cadastradas. Emissões envolvidas: %d. Empreendimentos envolvidos: %d.',
                        $result['units'],
                        $result['emissions'],
                        $result['constructions'],
                    ),
                    $result['run'],
                );
            });
    }

    /**
     * Arquiva a planilha confirmada, roda a importação num batch da trilha e
     * descarta o envio temporário. Se a importação falhar, a planilha arquivada
     * não fica para trás sem registro que aponte para ela.
     *
     * @template TResult
     *
     * @param  Closure(string): TResult  $import
     * @return TResult
     */
    private function runArchived(ImportSpreadsheetSource $source, string $directory, Closure $import): mixed
    {
        $archivedPath = $source->archive($directory);

        try {
            $result = app(LogBatch::class)->withinBatch(fn (): mixed => $import($archivedPath));
        } catch (Throwable $exception) {
            ImportSpreadsheetSource::forgetArchive($archivedPath);

            throw $exception;
        }

        $source->discard();

        return $result;
    }

    private static function draftFor(string $type, ImportSpreadsheetSource $source, string $archivedPath): ImportRunDraft
    {
        $userId = auth()->id();

        return new ImportRunDraft(
            type: $type,
            fileName: $source->originalName(),
            checksum: $source->checksum(),
            filePath: $archivedPath,
            userId: $userId === null ? null : (int) $userId,
        );
    }

    private function notifyCompleted(string $title, string $body, ?ImportRun $run): void
    {
        $notification = Notification::make()
            ->success()
            ->title($title)
            ->body($body)
            ->persistent();

        if (($run !== null) && ImportRunResource::canViewAny()) {
            $notification->actions([
                Action::make('viewImportRun')
                    ->label('Ver detalhes da importação')
                    ->url(ImportRunResource::getUrl('view', ['record' => $run])),
            ]);
        }

        $notification->send();
    }

    /**
     * Atualização de valores em lote.
     *
     * Fluxo separado do cadastro de propósito: aqui cada linha aprovada vira uma
     * linha nova do histórico financeiro da unidade, e uma unidade que não
     * existe é erro -- reprecificar não cria cadastro.
     */
    private function updateValuesAction(): Action
    {
        return Action::make('updateUnitValues')
            ->label('Atualizar Valores')
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->modalHeading('Atualizar valores das unidades')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Confirmar atualização')
            ->visible(fn (): bool => auth()->user()?->can('constructions.update') ?? false)
            ->steps([
                Step::make('Arquivo')
                    ->description('Selecione a planilha preenchida')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Planilha de Valores (.xlsx)')
                            ->disk('local')
                            ->storeFiles(false)
                            ->acceptedFileTypes((array) config('uploads.spreadsheet_import.allowed_mimes', []))
                            ->rules([new XlsxSpreadsheetFile])
                            ->validationMessages(['mimetypes' => XlsxSpreadsheetFile::MESSAGE])
                            ->required()
                            ->live()
                            ->helperText('As unidades precisam já estar cadastradas. Esta planilha não cria unidades.'),

                        Textarea::make('batch_reason')
                            ->label('Motivo do lote')
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder('Reajuste anual da tabela, revisão comercial...')
                            ->helperText('Usado nas linhas que não trouxerem motivo próprio na planilha.'),
                    ]),

                Step::make('Conferência')
                    ->description('Revise antes de confirmar')
                    ->schema([
                        Placeholder::make('valuePreview')
                            ->hiddenLabel()
                            ->content(fn (Get $get): Htmlable => $this->renderValuePreview($get('file'))),
                    ]),
            ])
            ->action(function (array $data): void {
                $source = ImportSpreadsheetSource::fromState($data['file'] ?? null);
                $analysis = $source === null ? null : $this->analyzeValues($source);

                if (($source === null) || ($analysis === null) || ! $analysis->canImport()) {
                    Notification::make()
                        ->danger()
                        ->title('Atualização não realizada.')
                        ->body('Corrija as inconsistências apontadas na conferência e envie a planilha novamente.')
                        ->persistent()
                        ->send();

                    return;
                }

                $result = $this->runArchived($source, 'imports/construction-unit-values', function (string $archivedPath) use ($analysis, $source, $data): array {
                    $result = app(ImportUnitValuesFromSpreadsheet::class)->handle(
                        $analysis,
                        batchReason: filled($data['batch_reason'] ?? null) ? trim((string) $data['batch_reason']) : null,
                        draft: self::draftFor(ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES, $source, $archivedPath),
                    );

                    activity('atualizacao-valores-unidades')
                        ->causedBy(auth()->user())
                        ->withProperties([
                            'arquivo' => $source->originalName(),
                            'importacao_id' => $result['run']?->getKey(),
                            'valores_registrados' => $result['created'],
                            'linhas_sem_alteracao' => $result['unchanged'],
                            'unidades_envolvidas' => $result['units'],
                        ])
                        ->log('Atualização de valores das unidades concluída.');

                    return $result;
                });

                $this->notifyCompleted(
                    $result['created'] > 0 ? 'Valores atualizados.' : 'Nenhuma alteração a registrar.',
                    sprintf(
                        '%d atualizações registradas. %d linhas já estavam com o valor vigente.',
                        $result['created'],
                        $result['unchanged'],
                    ),
                    $result['run'],
                );
            });
    }

    private function renderValuePreview(mixed $file): Htmlable
    {
        $source = ImportSpreadsheetSource::fromState($file);
        $analysis = $source === null ? null : $this->analyzeValues($source);

        if ($analysis === null) {
            return new HtmlString('<p class="fi-color-danger">Não foi possível ler a planilha enviada.</p>');
        }

        if ($analysis->fileErrors !== []) {
            return new HtmlString('<p class="fi-color-danger"><b>'.e(implode(' ', $analysis->fileErrors)).'</b></p>');
        }

        $lines = [
            'Total de linhas: <b>'.$analysis->totalLines().'</b>',
            'Novos valores: <b>'.$analysis->newCount().'</b>',
            'Atualizações: <b>'.$analysis->updateCount().'</b>',
            'Sem alteração: <b>'.$analysis->unchangedCount().'</b>',
            'Conflitos: <b>'.$analysis->conflictCount().'</b>',
            'Com erro: <b>'.$analysis->errorCount().'</b>',
            'Duplicadas na planilha: <b>'.$analysis->duplicatedInFileCount().'</b>',
        ];

        if ($analysis->informativeDivergenceCount() > 0) {
            $lines[] = 'Divergências informativas: <b>'.$analysis->informativeDivergenceCount().'</b>';
        }

        if ($analysis->warningCount() > 0) {
            $lines[] = 'Com aviso: <b>'.$analysis->warningCount().'</b>';
        }

        if ($analysis->registeredCompetenceCount() > 0) {
            $lines[] = 'Alcançam competência já registrada no Quadro de Vendas: <b>'.$analysis->registeredCompetenceCount().'</b>';
        }

        $verdict = $analysis->canImport()
            ? '<p class="fi-color-success"><b>Planilha pronta para atualização.</b></p>'
            : '<p class="fi-color-danger"><b>Corrija as inconsistências antes de confirmar.</b></p>';

        if ($analysis->informativeDivergenceCount() > 0) {
            $verdict .= '<p class="fi-color-warning"><b>'.$analysis->informativeDivergenceCount().' linha(s) trazem um valor que já vigora desde uma data registrada com dia e mês trocados ou anterior a 1990. Nada será gravado por elas: confira a instrução de cada uma.</b></p>';
        }

        $verdict .= $this->renderWarningVerdict($analysis->warningCount());
        $verdict .= $this->renderRegisteredCompetenceVerdict($analysis->registeredCompetenceCount());

        $rows = $analysis->previewRows();
        $renderedRows = $rows->take(self::PREVIEW_LIMIT)->map(function (array $row): string {
            $message = filled($row['message'] ?? null) ? ' — '.e((string) $row['message']) : '';
            $message .= $this->renderWarningNotes($row);
            $message .= $this->renderRegisteredCompetenceNote($row);

            /**
             * A vigência como a importação a entendeu, e não como o arquivo a
             * escreveu: é aqui que uma data lida no mês errado aparece antes
             * de gravar. Sem data interpretada (linha com erro), o texto do
             * arquivo.
             */
            $effectiveFrom = filled($row['effective_from_date'] ?? null)
                ? SpreadsheetDate::display($row['effective_from_date'])
                : (string) ($row['effective_from'] ?? '—');

            return '<tr>'
                .'<td style="padding:.25rem .5rem;">'.$row['line'].'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['construction']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['block']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['unit']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($this->formatPreviewValue($row['current_value_cents'] ?? null)).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e(SpreadsheetDate::display($row['current_effective_from'] ?? null)).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($this->formatPreviewValue($row['value_cents'] ?? null)).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($effectiveFrom).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($row['outcome']->label()).$message.'</td>'
                .'</tr>';
        })->implode('');

        $omitted = $rows->count() > self::PREVIEW_LIMIT
            ? '<p>Exibindo as primeiras '.self::PREVIEW_LIMIT.' de '.$rows->count().' linhas, em ordem de prioridade: bloqueantes, avisos, divergências e competências registradas, atualizações, novos e sem alteração.</p>'
            : '';

        $table = '<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Linha</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Empreendimento</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Bloco</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Unidade</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Valor vigente</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Vigência registrada</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Novo valor</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Vigência</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Situação</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'.$omitted;

        return new HtmlString('<div class="fi-ta-text-item-label">'.implode(' &nbsp;·&nbsp; ', $lines).'</div>'.$verdict.$table);
    }

    private function formatPreviewValue(?int $cents): string
    {
        return $cents === null ? '—' : 'R$ '.IntegerMoney::format($cents);
    }

    /**
     * Nunca bloqueia: reprecificar com vigência passada, ou cadastrar unidade
     * em empreendimento já posicionado, é legítimo. Mas a posição registrada é
     * uma foto que garantias e relatório mensal continuam lendo, e ela não
     * acompanha a fonte sozinha.
     */
    private function renderRegisteredCompetenceVerdict(int $count): string
    {
        if ($count === 0) {
            return '';
        }

        return '<p class="fi-color-warning"><b>'.$count.' linha(s) alcançam competências já registradas no Quadro de Vendas (marcadas com ⚑). A posição registrada não muda sozinha: na competência publicada pelo ciclo, o valor novo entra na próxima competência; na registrada manualmente, revise o quadro dela.</b></p>';
    }

    /**
     * Nunca bloqueia: um valor lido de forma duvidosa, ou longe do esperado, é
     * gravado como lido -- mas quem confirma vê antes.
     */
    private function renderWarningVerdict(int $count): string
    {
        if ($count === 0) {
            return '';
        }

        return '<p class="fi-color-warning"><b>'.$count.' linha(s) têm aviso (marcadas com ⚠). Confira os valores antes de confirmar.</b></p>';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function renderWarningNotes(array $row): string
    {
        $notes = '';

        foreach ($row['warnings'] ?? [] as $warning) {
            $notes .= '<br><span class="fi-color-warning">⚠ '.e((string) ($warning['message'] ?? '')).'</span>';
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function renderRegisteredCompetenceNote(array $row): string
    {
        $warning = $row['registered_competence_notice']
            ?? RegisteredCompetenceIndex::describe($row['registered_competences'] ?? []);

        return $warning === null ? '' : '<br><span class="fi-color-warning">⚑ '.e($warning).'</span>';
    }

    private function analyzeValues(ImportSpreadsheetSource $source): ?UnitValueSpreadsheetAnalysis
    {
        try {
            $checksum = $source->checksum();

            return $this->valueAnalyses[$checksum] ??= XlsxSpreadsheetFile::isSpreadsheet($source->file())
                ? $source->read(fn (string $path): UnitValueSpreadsheetAnalysis => app(AnalyzeUnitValueSpreadsheet::class)->handle($path))
                : new UnitValueSpreadsheetAnalysis(fileErrors: [XlsxSpreadsheetFile::MESSAGE]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function renderPreview(mixed $file): Htmlable
    {
        $source = ImportSpreadsheetSource::fromState($file);
        $analysis = $source === null ? null : $this->analyze($source);

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

    private function renderSummary(ConstructionUnitSpreadsheetAnalysis $analysis): string
    {
        $lines = [
            'Total de linhas: <b>'.$analysis->totalLines().'</b>',
            'Válidas: <b>'.$analysis->validCount().'</b>',
            'Com erro: <b>'.$analysis->errorCount().'</b>',
            'Já cadastradas: <b>'.$analysis->alreadyRegisteredCount().'</b>',
            'Duplicadas na planilha: <b>'.$analysis->duplicatedInFileCount().'</b>',
        ];

        if ($analysis->warningCount() > 0) {
            $lines[] = 'Com aviso: <b>'.$analysis->warningCount().'</b>';
        }

        if ($analysis->registeredCompetenceCount() > 0) {
            $lines[] = 'Em empreendimento com competência já registrada no Quadro de Vendas: <b>'.$analysis->registeredCompetenceCount().'</b>';
        }

        if ($analysis->emptyLineCount() > 0) {
            $lines[] = 'Linhas vazias ignoradas: <b>'.$analysis->emptyLineCount().'</b>';
        }

        $verdict = $analysis->canImport()
            ? '<p class="fi-color-success"><b>Planilha pronta para importação.</b></p>'
            : '<p class="fi-color-danger"><b>Corrija as inconsistências antes de confirmar. A importação só é liberada quando todas as linhas estiverem válidas.</b></p>';

        $verdict .= $this->renderWarningVerdict($analysis->warningCount());
        $verdict .= $this->renderRegisteredCompetenceVerdict($analysis->registeredCompetenceCount());

        return '<div class="fi-ta-text-item-label">'.implode(' &nbsp;·&nbsp; ', $lines).'</div>'.$verdict;
    }

    private function renderTable(ConstructionUnitSpreadsheetAnalysis $analysis): string
    {
        $rows = $analysis->previewRows();
        $renderedRows = $rows->take(self::PREVIEW_LIMIT)->map(function (array $row): string {
            $status = ConstructionUnitSpreadsheetAnalysis::statusLabel($row['status']);
            $message = filled($row['message'] ?? null) ? ' — '.e((string) $row['message']) : '';
            $message .= $this->renderWarningNotes($row);
            $message .= $this->renderRegisteredCompetenceNote($row);

            return '<tr>'
                .'<td style="padding:.25rem .5rem;">'.$row['line'].'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['emission']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['construction']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['block']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['unit']).'</td>'
                /**
                 * Valor base e data de referência como a importação os
                 * entendeu, antes de gravar.
                 */
                .'<td style="padding:.25rem .5rem;text-align:right;white-space:nowrap;">'.e($this->formatPreviewValue($row['base_value'] ?? null)).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e(SpreadsheetDate::display($row['base_value_reference_date'] ?? null)).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($status).$message.'</td>'
                .'</tr>';
        })->implode('');

        $omitted = $rows->count() > self::PREVIEW_LIMIT
            ? '<p>Exibindo as primeiras '.self::PREVIEW_LIMIT.' de '.$rows->count().' linhas, em ordem de prioridade: bloqueantes, avisos e competências registradas, e as demais válidas.</p>'
            : '';

        return '<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Linha</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Emissão</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Empreendimento</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Bloco</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Unidade</th>'
            .'<th style="text-align:right;padding:.25rem .5rem;">Valor base</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Referência</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Status</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'.$omitted;
    }

    private function analyze(ImportSpreadsheetSource $source): ?ConstructionUnitSpreadsheetAnalysis
    {
        try {
            $checksum = $source->checksum();

            return $this->unitAnalyses[$checksum] ??= XlsxSpreadsheetFile::isSpreadsheet($source->file())
                ? $source->read(fn (string $path): ConstructionUnitSpreadsheetAnalysis => app(AnalyzeConstructionUnitSpreadsheet::class)->handle($path))
                : new ConstructionUnitSpreadsheetAnalysis(fileErrors: [XlsxSpreadsheetFile::MESSAGE]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
