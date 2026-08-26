<?php

namespace App\Filament\Resources\ConstructionUnits\Pages;

use App\Actions\ConstructionUnits\AnalyzeConstructionUnitSpreadsheet;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetAnalysis;
use App\Actions\ConstructionUnits\ImportConstructionUnitsFromSpreadsheet;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
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
     * Memoized analysis, so moving through the wizard does not re-read the file
     * on every render.
     *
     * @var array{path: string, analysis: ConstructionUnitSpreadsheetAnalysis}|null
     */
    private ?array $memoizedAnalysis = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')
                ->label('Baixar Modelo')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Baixar a planilha padrão de cadastro de unidades')
                ->url(fn (): string => route('admin.construction-units.template.download')),

            $this->importAction(),

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
                            ->directory('imports/construction-units')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                                'application/csv',
                            ])
                            ->required()
                            ->live()
                            ->helperText('Utilize a planilha padrão. Emissões e empreendimentos precisam já existir no sistema.'),
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

                $result = app(ImportConstructionUnitsFromSpreadsheet::class)->handle($analysis);

                activity('importacao-unidades')
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'arquivo' => basename((string) $this->resolvePath($data['file'] ?? null)),
                        'unidades_cadastradas' => $result['units'],
                        'empreendimentos_envolvidos' => $result['constructions'],
                        'emissoes_envolvidas' => $result['emissions'],
                    ])
                    ->log('Importação de unidades concluída.');

                Notification::make()
                    ->success()
                    ->title('Importação concluída com sucesso.')
                    ->body(sprintf(
                        '%d unidades cadastradas. Emissões envolvidas: %d. Empreendimentos envolvidos: %d.',
                        $result['units'],
                        $result['emissions'],
                        $result['constructions'],
                    ))
                    ->persistent()
                    ->send();
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

    private function renderSummary(ConstructionUnitSpreadsheetAnalysis $analysis): string
    {
        $lines = [
            'Total de linhas: <b>'.$analysis->totalLines().'</b>',
            'Válidas: <b>'.$analysis->validCount().'</b>',
            'Com erro: <b>'.$analysis->errorCount().'</b>',
            'Já cadastradas: <b>'.$analysis->alreadyRegisteredCount().'</b>',
            'Duplicadas na planilha: <b>'.$analysis->duplicatedInFileCount().'</b>',
        ];

        if ($analysis->emptyLineCount() > 0) {
            $lines[] = 'Linhas vazias ignoradas: <b>'.$analysis->emptyLineCount().'</b>';
        }

        $verdict = $analysis->canImport()
            ? '<p class="fi-color-success"><b>Planilha pronta para importação.</b></p>'
            : '<p class="fi-color-danger"><b>Corrija as inconsistências antes de confirmar. A importação só é liberada quando todas as linhas estiverem válidas.</b></p>';

        return '<div class="fi-ta-text-item-label">'.implode(' &nbsp;·&nbsp; ', $lines).'</div>'.$verdict;
    }

    private function renderTable(ConstructionUnitSpreadsheetAnalysis $analysis): string
    {
        $rows = $analysis->previewRows();
        $renderedRows = $rows->take(self::PREVIEW_LIMIT)->map(function (array $row): string {
            $status = ConstructionUnitSpreadsheetAnalysis::statusLabel($row['status']);
            $message = filled($row['message'] ?? null) ? ' — '.e((string) $row['message']) : '';

            return '<tr>'
                .'<td style="padding:.25rem .5rem;">'.$row['line'].'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['emission']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['construction']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['block']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['unit']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($status).$message.'</td>'
                .'</tr>';
        })->implode('');

        $omitted = $rows->count() > self::PREVIEW_LIMIT
            ? '<p>Exibindo as primeiras '.self::PREVIEW_LIMIT.' de '.$rows->count().' linhas.</p>'
            : '';

        return '<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Linha</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Emissão</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Empreendimento</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Bloco</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Unidade</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Status</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'.$omitted;
    }

    private function analyze(?string $path): ?ConstructionUnitSpreadsheetAnalysis
    {
        if (blank($path) || ! is_file($path)) {
            return null;
        }

        if (($this->memoizedAnalysis['path'] ?? null) === $path) {
            return $this->memoizedAnalysis['analysis'];
        }

        $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle($path);

        $this->memoizedAnalysis = ['path' => $path, 'analysis' => $analysis];

        return $analysis;
    }

    /**
     * The upload state is a temporary file while the wizard is open and a stored
     * path once the step is dehydrated.
     */
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
