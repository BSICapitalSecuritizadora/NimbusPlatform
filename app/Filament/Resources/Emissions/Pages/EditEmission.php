<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Actions\Emissions\ValidatePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveExportService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Schemas\EmissionForm;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\EmissionPuDailyCurve;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;

class EditEmission extends EditRecord
{
    protected static string $resource = EmissionResource::class;

    protected static ?string $title = 'Editar Emissao';

    protected Width|string|null $maxContentWidth = Width::Full;

    public bool $isExtractingClauses = false;

    public bool $isGeneratingPuCurve = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (Cache::get("gemini_extraction_{$this->record->id}_status") === 'processing') {
            $this->isExtractingClauses = true;
        }

        if (Cache::get($this->puCurveGenerationStatusCacheKey()) === 'processing') {
            $this->isGeneratingPuCurve = true;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('configurePuCalculation')
                ->label('Configurar Calculo de PU')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('emissions.update') ?? false)
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalHeading('Configurar Calculo de PU')
                ->fillForm(fn (): array => $this->getPuCalculationDefaults())
                ->form($this->getPuCalculationForm())
                ->action(function (array $data): void {
                    $this->getRecord()->puParameter()->updateOrCreate([], $data);

                    $this->getRecord()->unsetRelation('puParameter');
                    $this->getRecord()->load('puParameter');

                    Notification::make()
                        ->title('Parametros do calculo de PU atualizados.')
                        ->success()
                        ->send();
                }),
            Action::make('generatePuDailyCurve')
                ->label(fn (): string => $this->isGeneratingPuCurve ? 'Gerando Curva PU...' : 'Gerar Curva PU')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->can('emissions.update') ?? false)
                ->disabled(fn (): bool => $this->isGeneratingPuCurve)
                ->requiresConfirmation()
                ->modalHeading('Gerar Curva PU')
                ->modalDescription('A curva diaria sera recalculada em segundo plano e persistida como uma nova versao.')
                ->action(function (): void {
                    if ($this->isGeneratingPuCurve || Cache::get($this->puCurveGenerationStatusCacheKey()) === 'processing') {
                        Notification::make()
                            ->title('Geracao ja em andamento.')
                            ->body('Aguarde a conclusao da geracao atual antes de iniciar uma nova.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $emission = $this->getRecord()->loadMissing(['puParameter', 'puEvents', 'integralizationHistories']);
                    $prerequisiteCheck = app(PuCurvePrerequisiteService::class)->handle($emission);

                    if (! $prerequisiteCheck->passes()) {
                        Notification::make()
                            ->title('Geracao bloqueada por dados incompletos.')
                            ->body($prerequisiteCheck->blockingSummary())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    if ($prerequisiteCheck->warningMessages() !== []) {
                        Notification::make()
                            ->title('Geracao iniciada com alertas.')
                            ->body(implode("\n", $prerequisiteCheck->warningMessages()))
                            ->warning()
                            ->persistent()
                            ->send();
                    }

                    Cache::put($this->puCurveGenerationStatusCacheKey(), 'processing', 1800);
                    $this->isGeneratingPuCurve = true;

                    GeneratePuDailyCurveJob::dispatch($emission->id, auth()->id());

                    Notification::make()
                        ->title('Geracao da curva iniciada.')
                        ->body('O calculo foi enviado para a fila e a pagina sera atualizada ao concluir.')
                        ->info()
                        ->send();
                }),
            Action::make('viewPuDailyCurve')
                ->label('Visualizar Curva PU Diario')
                ->icon('heroicon-o-chart-bar-square')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false)
                ->modalWidth(Width::SevenExtraLarge)
                ->modalHeading('Curva PU Diario')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(fn () => view('filament.emissions.pu-curve-summary', [
                    'emission' => $this->getRecord(),
                    'summary' => app(PuCurveExportService::class)->summary($this->getRecord()),
                    'rows' => app(PuCurveExportService::class)->rows($this->getRecord())->take(30),
                ])),
            Action::make('validatePuDailyCurve')
                ->label('Validar contra Planilha')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false)
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalHeading('Validar Curva PU')
                ->fillForm(fn (): array => [
                    'reference_spreadsheet' => $this->defaultSpreadsheetSelection(),
                    'calculation_version' => EmissionPuDailyCurve::latestCalculationVersionForEmission($this->getRecord()->id),
                    'validation_mode' => PuValidationMode::DisplayScale->value,
                ])
                ->form([
                    Select::make('calculation_version')
                        ->label('Versao da curva')
                        ->options($this->getCalculationVersionOptions())
                        ->placeholder('Usar a versao mais recente'),
                    Select::make('validation_mode')
                        ->label('Modo de validacao')
                        ->options([
                            PuValidationMode::DisplayScale->value => 'Display-scale',
                            PuValidationMode::RawScale->value => 'Raw-scale',
                        ])
                        ->required(),
                    DatePicker::make('range_start')
                        ->label('Data inicial da analise'),
                    DatePicker::make('range_end')
                        ->label('Data final da analise'),
                    Select::make('reference_spreadsheet')
                        ->label('Planilha de referencia')
                        ->options(app(PuValidationSpreadsheetLocatorService::class)->options())
                        ->searchable()
                        ->placeholder('Selecione uma planilha de validacao'),
                    FileUpload::make('spreadsheet_file')
                        ->label('Ou envie uma planilha .xlsx')
                        ->disk('local')
                        ->directory('imports/pu-validation')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                ])
                ->action(function (array $data): void {
                    $spreadsheetPath = $this->resolveValidationSpreadsheetPath($data);

                    if ($spreadsheetPath === null) {
                        Notification::make()
                            ->title('Validacao nao executada.')
                            ->body('Selecione uma planilha de referencia ou envie um arquivo .xlsx.')
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $mode = PuValidationMode::from((string) ($data['validation_mode'] ?? PuValidationMode::DisplayScale->value));
                    $rangeStart = filled($data['range_start'] ?? null) ? CarbonImmutable::parse((string) $data['range_start']) : null;
                    $rangeEnd = filled($data['range_end'] ?? null) ? CarbonImmutable::parse((string) $data['range_end']) : null;

                    $report = app(ValidatePuDailyCurve::class)->handle(
                        $this->getRecord(),
                        $spreadsheetPath,
                        $data['calculation_version'] ?? null,
                        $mode,
                        $rangeStart,
                        $rangeEnd,
                        auth()->id(),
                    );

                    $summaryLines = [
                        sprintf('Modo: %s', $report->mode->value),
                        sprintf('Linhas comparadas: %d', $report->totalRowsCompared),
                        sprintf('Linhas divergentes: %d', $report->totalDivergences),
                        sprintf('Campos divergentes: %d', $report->totalFieldDivergences),
                        sprintf('Primeira divergencia: %s', $report->firstDivergenceDate?->toDateString() ?? '-'),
                        sprintf('Maior diferenca de PU: %s', $report->largestPuDifference),
                        sprintf('Maior diferenca de valor total: %s', $report->largestTotalValueDifference),
                        sprintf('Maior diferenca de pagamento: %s', $report->largestPaymentDifference),
                    ];

                    $detailedLines = collect($report->divergentRows(3))
                        ->flatMap(function ($row): array {
                            return collect($row->differences)
                                ->take(2)
                                ->map(fn ($difference): string => sprintf(
                                    '%s | %s | dif=%s | severidade=%s',
                                    $row->date->format('d/m/Y'),
                                    $difference->label,
                                    $difference->absoluteDifference ?? '-',
                                    $difference->severity?->value ?? 'alta',
                                ))
                                ->all();
                        })
                        ->all();

                    $notification = Notification::make()
                        ->title($report->status->value === 'approved' ? 'Validacao aprovada.' : 'Validacao reprovada.')
                        ->body(implode("\n", array_merge($summaryLines, $detailedLines)))
                        ->persistent();

                    if ($report->status->value === 'approved') {
                        $notification->success();
                    } else {
                        $notification->danger();
                    }

                    $notification->send();
                }),
            Action::make('viewPuValidationReport')
                ->label('Ver Relatorio de Divergencias')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false)
                ->modalWidth(Width::SevenExtraLarge)
                ->modalHeading('Ultimo Relatorio de Validacao')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(fn () => view('filament.emissions.pu-validation-report', [
                    'activity' => app(PuAuditLogService::class)->latestValidationActivity($this->getRecord()),
                ])),
            Action::make('exportPuDailyCurve')
                ->label('Exportar Curva PU')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false)
                ->modalHeading('Exportar Curva PU')
                ->fillForm(fn (): array => [
                    'calculation_version' => EmissionPuDailyCurve::latestCalculationVersionForEmission($this->getRecord()->id),
                ])
                ->form([
                    Select::make('calculation_version')
                        ->label('Versao da curva')
                        ->options($this->getCalculationVersionOptions())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $calculationVersion = $data['calculation_version'] ?? null;

                    if (! filled($calculationVersion)) {
                        Notification::make()
                            ->title('Nenhuma versao de curva disponivel para exportacao.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $this->redirect(route('admin.emissions.pu-curves.export', [
                        'emission' => $this->getRecord(),
                        'calculation_version' => $calculationVersion,
                    ]), navigate: false);
                }),
            DeleteAction::make()
                ->label('Excluir Emissao')
                ->modalHeading('Excluir Emissao'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $integralizedQuantity = $this->getRecord()->calculateIntegralizedQuantity();
        $remainingQuantity = max(
            0,
            (int) ($data['issued_quantity'] ?? 0) - $integralizedQuantity,
        );
        $data['integralized_quantity'] = EmissionForm::formatQuantityForDisplay($integralizedQuantity);
        $data['remaining_quantity'] = EmissionForm::formatQuantityForDisplay($remainingQuantity);

        return $data;
    }

    public function checkGeminiExtractionStatus(): void
    {
        $status = Cache::get("gemini_extraction_{$this->record->id}_status");

        if ($status === 'completed') {
            $this->isExtractingClauses = false;
            Cache::forget("gemini_extraction_{$this->record->id}_status");

            Notification::make()
                ->title('Clausulas extraidas com sucesso')
                ->body('Os campos foram preenchidos com os dados extraidos do Termo de Securitizacao. Revise e salve.')
                ->success()
                ->send();

            $this->redirect(
                EmissionResource::getUrl('edit', ['record' => $this->record]),
                navigate: true,
            );

            return;
        }

        if (is_array($status) && isset($status['error'])) {
            $this->isExtractingClauses = false;
            Cache::forget("gemini_extraction_{$this->record->id}_status");

            Notification::make()
                ->title('Falha na extracao das clausulas')
                ->body($status['error'])
                ->danger()
                ->send();
        }
    }

    public function checkPuCurveGenerationStatus(): void
    {
        $status = Cache::get($this->puCurveGenerationStatusCacheKey());

        if ($status === 'processing') {
            return;
        }

        if ($status === null) {
            $this->isGeneratingPuCurve = false;

            return;
        }

        $this->isGeneratingPuCurve = false;
        Cache::forget($this->puCurveGenerationStatusCacheKey());

        if (is_array($status) && (($status['status'] ?? null) === 'completed')) {
            Notification::make()
                ->title('Curva diaria gerada com sucesso.')
                ->body(sprintf(
                    'Versao %s gerada com %d linhas.',
                    $status['calculation_version'] ?? 'v1',
                    (int) ($status['rows_count'] ?? 0),
                ))
                ->success()
                ->send();

            $this->redirect(
                EmissionResource::getUrl('edit', ['record' => $this->record]),
                navigate: true,
            );

            return;
        }

        if (is_array($status) && isset($status['error'])) {
            Notification::make()
                ->title('Falha ao gerar a curva diaria de PU.')
                ->body((string) $status['error'])
                ->danger()
                ->persistent()
                ->send();
        }
    }

    #[On('integralization-histories-updated')]
    public function refreshIntegralizedQuantity(): void
    {
        $this->getRecord()->refresh();

        $this->refreshFormData(['integralized_quantity', 'remaining_quantity']);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Emissao atualizada com sucesso.';
    }

    private function puCurveGenerationStatusCacheKey(): string
    {
        return sprintf('pu_curve_generation_%d_status', $this->getRecord()->id);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private function getPuCalculationForm(): array
    {
        return [
            DatePicker::make('curve_start_date')
                ->label('Inicio da curva')
                ->required(),
            DatePicker::make('curve_end_date')
                ->label('Fim da curva')
                ->required(),
            TextInput::make('initial_unit_value')
                ->label('Valor unitario inicial')
                ->required()
                ->inputMode('decimal'),
            TextInput::make('spread_rate')
                ->label('Spread (% a.a.)')
                ->required()
                ->inputMode('decimal'),
            Select::make('indexer')
                ->label('Indexador')
                ->options([
                    PuIndexer::Cdi->value => 'CDI',
                ])
                ->required(),
            TextInput::make('business_day_basis')
                ->label('Base de dias uteis')
                ->numeric()
                ->default(252)
                ->required(),
            TextInput::make('calendar_code')
                ->label('Calendario')
                ->default('B3')
                ->required(),
            Select::make('index_rate_lookup_mode')
                ->label('Modo de consulta do CDI')
                ->options([
                    PuIndexRateLookupMode::PreviousAvailableBusinessDay->value => 'Ultimo CDI disponivel no dia util',
                    PuIndexRateLookupMode::PreviousCalendarDayExact->value => 'CDI exato do dia calendario anterior',
                    PuIndexRateLookupMode::BusinessDayLagExact->value => 'CDI exato com defasagem em dias uteis',
                ])
                ->live()
                ->required(),
            TextInput::make('index_rate_lag_business_days')
                ->label('Defasagem util do CDI')
                ->numeric()
                ->default(1)
                ->required(fn (Get $get): bool => $get('index_rate_lookup_mode') === PuIndexRateLookupMode::BusinessDayLagExact->value)
                ->visible(fn (Get $get): bool => $get('index_rate_lookup_mode') === PuIndexRateLookupMode::BusinessDayLagExact->value),
            Toggle::make('legacy_projection_enabled')
                ->label('Atualizar projecoes legadas (payments / pu_histories)')
                ->default(true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPuCalculationDefaults(): array
    {
        $parameter = $this->getRecord()->puParameter;

        return [
            'curve_start_date' => $parameter?->curve_start_date?->toDateString() ?? $this->getRecord()->issue_date?->toDateString(),
            'curve_end_date' => $parameter?->curve_end_date?->toDateString() ?? $this->getRecord()->maturity_date?->toDateString(),
            'initial_unit_value' => $parameter?->getRawOriginal('initial_unit_value') ?? $this->getRecord()->getRawOriginal('issued_price') ?? '1000.0000000000000000',
            'spread_rate' => $parameter?->getRawOriginal('spread_rate') ?? $this->getRecord()->getRawOriginal('remuneration_rate') ?? '0.00000000',
            'indexer' => $parameter?->indexer ?? PuIndexer::Cdi->value,
            'business_day_basis' => $parameter?->business_day_basis ?? 252,
            'calendar_code' => $parameter?->calendar_code ?? 'B3',
            'index_rate_lookup_mode' => $parameter?->index_rate_lookup_mode ?? PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
            'index_rate_lag_business_days' => $parameter?->index_rate_lag_business_days ?? 1,
            'legacy_projection_enabled' => $parameter?->legacy_projection_enabled ?? true,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getCalculationVersionOptions(): array
    {
        return EmissionPuDailyCurve::query()
            ->where('emission_id', $this->getRecord()->id)
            ->orderByDesc('id')
            ->pluck('calculation_version', 'calculation_version')
            ->unique()
            ->all();
    }

    private function defaultSpreadsheetSelection(): ?string
    {
        $options = app(PuValidationSpreadsheetLocatorService::class)->options();

        return array_key_first($options);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveValidationSpreadsheetPath(array $data): ?string
    {
        if (filled($data['spreadsheet_file'] ?? null)) {
            return Storage::disk('local')->path((string) $data['spreadsheet_file']);
        }

        if (filled($data['reference_spreadsheet'] ?? null)) {
            return app(PuValidationSpreadsheetLocatorService::class)->resolve((string) $data['reference_spreadsheet']);
        }

        return null;
    }
}
