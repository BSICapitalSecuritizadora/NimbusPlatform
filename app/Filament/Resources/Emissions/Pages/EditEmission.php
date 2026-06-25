<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveExportService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Domain\PuCalculator\Services\PuIndexCoverageService;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Schemas\EmissionForm;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Jobs\ValidatePuCurveJob;
use App\Models\EmissionPuDailyCurve;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
// Filament v5: closures de schema recebem Filament\Schemas\Components\Utilities\Get (NAO Filament\Forms\Get).
// Usar o import errado quebra o mount do formulario configurePuCalculation (campo CDI com Select->live()).
use Filament\Schemas\Components\Utilities\Get;
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

    public bool $isValidatingPuCurve = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (Cache::get("gemini_extraction_{$this->record->id}_status") === 'processing') {
            $this->isExtractingClauses = true;
        }

        if (Cache::get($this->puCurveGenerationStatusCacheKey()) === 'processing') {
            $this->isGeneratingPuCurve = true;
        }

        if (Cache::get($this->puCurveValidationStatusCacheKey()) === 'processing') {
            $this->isValidatingPuCurve = true;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('puCurvePanel')
                ->label('Painel da Curva PU')
                ->icon('heroicon-o-presentation-chart-line')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('pu.curve.view') ?? false)
                ->modalWidth(Width::FiveExtraLarge)
                ->modalHeading('Painel da Curva PU')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(fn () => view('filament.emissions.pu-curve-panel', [
                    'emission' => $this->getRecord(),
                    'version' => $this->getRecord()->currentPuCurveVersion(),
                    'coverage' => app(PuIndexCoverageService::class)->report($this->getRecord()),
                ])),
            Action::make('puCurveHistory')
                ->label('Historico / Auditoria')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('pu.curve.view') ?? false)
                ->url(fn (): string => EmissionResource::getUrl('pu-history', ['record' => $this->getRecord()])),
            Action::make('configurePuCalculation')
                ->label('Configurar Calculo de PU')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('pu.parameters.configure') ?? false)
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalHeading('Configurar Calculo de PU')
                ->fillForm(fn (): array => $this->getPuCalculationDefaults())
                ->form($this->getPuCalculationForm())
                ->action(function (array $data): void {
                    $before = $this->getRecord()->puParameter?->only(array_keys($data)) ?? [];

                    $this->getRecord()->puParameter()->updateOrCreate([], $data);

                    $this->getRecord()->unsetRelation('puParameter');
                    $this->getRecord()->load('puParameter');

                    app(PuAuditLogService::class)->logParametersUpdated(
                        $this->getRecord(),
                        $before,
                        $data,
                        auth()->id(),
                    );

                    Notification::make()
                        ->title('Parametros do calculo de PU atualizados.')
                        ->success()
                        ->send();
                }),
            Action::make('generatePuDailyCurve')
                ->label(fn (): string => $this->generatePuCurveLabel())
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->can('pu.curve.generate') ?? false)
                ->disabled(fn (): bool => $this->isGeneratingPuCurve)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->generatePuCurveLabel())
                ->modalDescription('O calculo roda em segundo plano (fila) e cria uma NOVA versao da curva. As versoes anteriores nao-homologadas viram "obsoletas" e o historico e sempre preservado. Acompanhe o andamento no Painel da Curva PU.')
                ->form(fn (): array => $this->getGeneratePuCurveForm())
                ->action(function (array $data): void {
                    if ($this->isGeneratingPuCurve || Cache::get($this->puCurveGenerationStatusCacheKey()) === 'processing') {
                        Notification::make()
                            ->title('Geracao ja em andamento.')
                            ->body('Aguarde a conclusao da geracao atual antes de iniciar uma nova.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $emission = $this->getRecord()->loadMissing(['puParameter', 'puEvents', 'integralizationHistories']);
                    $hasHomologated = app(PuCurveVersionService::class)->hasHomologatedVersion($emission);

                    if ($hasHomologated && ! (auth()->user()?->can('pu.curve.reprocess') ?? false)) {
                        Notification::make()
                            ->title('Reprocessamento nao autorizado.')
                            ->body('Existe uma curva homologada. Voce nao possui permissao para reprocessar.')
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    if ($hasHomologated && ($data['confirm_reprocess'] ?? false) !== true) {
                        Notification::make()
                            ->title('Confirmacao necessaria.')
                            ->body('Marque a confirmacao para reprocessar uma curva homologada.')
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

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

                    GeneratePuDailyCurveJob::dispatch($emission->id, auth()->id(), $hasHomologated);

                    Notification::make()
                        ->title('Geracao da curva iniciada.')
                        ->body('O calculo foi enviado para a fila e a pagina sera atualizada ao concluir.')
                        ->info()
                        ->send();
                }),
            Action::make('homologatePuCurve')
                ->label('Homologar Curva')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => auth()->user()?->can('pu.curve.homologate') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Homologar Curva PU')
                ->modalDescription('A versao corrente sera marcada como homologada e protegida contra sobrescrita.')
                ->action(function (): void {
                    try {
                        $version = app(HomologatePuCurve::class)->handle($this->getRecord(), null, auth()->id());
                    } catch (\InvalidArgumentException|\App\Domain\PuCalculator\Exceptions\PuMakerCheckerException $exception) {
                        Notification::make()->title('Nao foi possivel homologar.')->body($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Curva homologada.')
                        ->body(sprintf('Versao %s homologada com sucesso.', $version->calculation_version))
                        ->success()
                        ->send();
                }),
            Action::make('invalidatePuCurve')
                ->label('Invalidar Curva')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->can('pu.curve.invalidate') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Invalidar Curva PU')
                ->modalDescription('A versao corrente sera marcada como obsoleta. O historico e preservado.')
                ->action(function (): void {
                    try {
                        $version = app(InvalidatePuCurve::class)->handle($this->getRecord(), null, auth()->id());
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()->title('Nao foi possivel invalidar.')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Curva invalidada.')
                        ->body(sprintf('Versao %s marcada como obsoleta.', $version->calculation_version))
                        ->success()
                        ->send();
                }),
            Action::make('viewPuDailyCurve')
                ->label('Visualizar Curva PU Diario')
                ->icon('heroicon-o-chart-bar-square')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('pu.curve.view') ?? false)
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
                ->label(fn (): string => $this->isValidatingPuCurve ? 'Validando...' : 'Validar contra Planilha')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('pu.curve.validate') ?? false)
                ->disabled(fn (): bool => $this->isValidatingPuCurve)
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
                    if ($this->isValidatingPuCurve || Cache::get($this->puCurveValidationStatusCacheKey()) === 'processing') {
                        Notification::make()
                            ->title('Validacao ja em andamento.')
                            ->body('Aguarde a conclusao da validacao atual.')
                            ->warning()
                            ->send();

                        return;
                    }

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

                    Cache::put($this->puCurveValidationStatusCacheKey(), 'processing', 1800);
                    $this->isValidatingPuCurve = true;

                    ValidatePuCurveJob::dispatch(
                        $this->getRecord()->id,
                        $spreadsheetPath,
                        $data['calculation_version'] ?? null,
                        $mode->value,
                        filled($data['range_start'] ?? null) ? (string) $data['range_start'] : null,
                        filled($data['range_end'] ?? null) ? (string) $data['range_end'] : null,
                        auth()->id(),
                    );

                    Notification::make()
                        ->title('Validacao iniciada.')
                        ->body('A validacao foi enviada para a fila e a pagina sera atualizada ao concluir.')
                        ->info()
                        ->send();
                }),
            Action::make('viewPuValidationReport')
                ->label('Ver Relatorio de Divergencias')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('pu.curve.view') ?? false)
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
                ->visible(fn (): bool => auth()->user()?->can('pu.curve.export') ?? false)
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

    public function checkPuCurveValidationStatus(): void
    {
        $status = Cache::get($this->puCurveValidationStatusCacheKey());

        if ($status === 'processing') {
            return;
        }

        if ($status === null) {
            $this->isValidatingPuCurve = false;

            return;
        }

        $this->isValidatingPuCurve = false;
        Cache::forget($this->puCurveValidationStatusCacheKey());

        if (is_array($status) && (($status['status'] ?? null) === 'completed')) {
            $approved = ($status['validation_status'] ?? null) === 'approved';

            Notification::make()
                ->title($approved ? 'Validacao aprovada.' : 'Validacao reprovada.')
                ->body(sprintf(
                    'Versao %s | Linhas comparadas: %d | Linhas divergentes: %d | Campos divergentes: %d',
                    $status['calculation_version'] ?? '-',
                    (int) ($status['total_rows_compared'] ?? 0),
                    (int) ($status['total_divergences'] ?? 0),
                    (int) ($status['total_field_divergences'] ?? 0),
                ))
                ->persistent()
                ->{$approved ? 'success' : 'danger'}()
                ->send();

            return;
        }

        if (is_array($status) && isset($status['error'])) {
            Notification::make()
                ->title('Falha ao validar a curva de PU.')
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

    private function puCurveValidationStatusCacheKey(): string
    {
        return sprintf('pu_curve_validation_%d_status', $this->getRecord()->id);
    }

    private function generatePuCurveLabel(): string
    {
        if ($this->isGeneratingPuCurve) {
            return 'Gerando Curva PU...';
        }

        return $this->getRecord()->currentPuCurveVersion() !== null ? 'Reprocessar Curva PU' : 'Gerar Curva PU';
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private function getGeneratePuCurveForm(): array
    {
        if (! app(PuCurveVersionService::class)->hasHomologatedVersion($this->getRecord())) {
            return [];
        }

        return [
            Placeholder::make('homologated_warning')
                ->label('')
                ->content('Esta emissao possui uma curva HOMOLOGADA. O reprocessamento ira: (1) criar uma NOVA versao da curva; (2) executar o calculo em segundo plano (fila); (3) PRESERVAR integralmente a curva homologada anterior, que permanece como registro historico. Nenhum dado e apagado.'),
            Checkbox::make('confirm_reprocess')
                ->label('Confirmo que entendo que uma nova versao sera criada e que a curva homologada anterior sera preservada.')
                ->accepted()
                ->required(),
        ];
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
            Select::make('indexer')
                ->label('Indexador')
                ->options([
                    PuIndexer::Cdi->value => PuIndexer::Cdi->label(),
                    PuIndexer::Prefixed->value => PuIndexer::Prefixed->label(),
                    PuIndexer::Ipca->value => PuIndexer::Ipca->label(),
                ])
                ->default(PuIndexer::Cdi->value)
                ->live()
                ->required(),
            Placeholder::make('ipca_notice')
                ->label('')
                ->content('Operacao IPCA: a geracao exige IPCA publicado cobrindo o periodo e, para meses futuros sob politica de mercado, uma SERIE PROJETADA APROVADA (maker/checker). A homologacao tambem exige maker/checker. Importe indices e aprove a serie em "Indices & Series Projetadas".')
                ->visible(fn (Get $get): bool => $get('indexer') === PuIndexer::Ipca->value),
            TextInput::make('spread_rate')
                ->label('Spread (% a.a.)')
                ->inputMode('decimal')
                ->required(fn (Get $get): bool => $get('indexer') === PuIndexer::Cdi->value)
                ->visible(fn (Get $get): bool => $get('indexer') === PuIndexer::Cdi->value),
            TextInput::make('annual_rate')
                ->label(fn (Get $get): string => $get('indexer') === PuIndexer::Ipca->value
                    ? 'Taxa real / cupom (% a.a.)'
                    : 'Taxa prefixada (% a.a.)')
                ->inputMode('decimal')
                ->required(fn (Get $get): bool => in_array($get('indexer'), [PuIndexer::Prefixed->value, PuIndexer::Ipca->value], true))
                ->visible(fn (Get $get): bool => in_array($get('indexer'), [PuIndexer::Prefixed->value, PuIndexer::Ipca->value], true)),
            DatePicker::make('base_index_date')
                ->label('Data-base do indice (aniversario de correcao)')
                ->required(fn (Get $get): bool => $get('indexer') === PuIndexer::Ipca->value)
                ->visible(fn (Get $get): bool => $get('indexer') === PuIndexer::Ipca->value),
            TextInput::make('index_lag_months')
                ->label('Defasagem do indice (meses)')
                ->numeric()
                ->default(2)
                ->required(fn (Get $get): bool => $get('indexer') === PuIndexer::Ipca->value)
                ->visible(fn (Get $get): bool => $get('indexer') === PuIndexer::Ipca->value),
            Select::make('correction_frequency')
                ->label('Frequencia de correcao')
                ->options([
                    'monthly' => 'Mensal',
                    'annual' => 'Anual',
                ])
                ->default('monthly')
                ->visible(fn (Get $get): bool => $get('indexer') === PuIndexer::Ipca->value),
            Select::make('index_projection_policy')
                ->label('Politica de projecao do IPCA')
                ->options([
                    IpcaProjectionPolicy::PublishedOnly->value => IpcaProjectionPolicy::PublishedOnly->label(),
                    IpcaProjectionPolicy::Market->value => IpcaProjectionPolicy::Market->label(),
                ])
                ->default(IpcaProjectionPolicy::PublishedOnly->value)
                ->helperText('"Somente publicado" bloqueia meses sem IPCA publicado. "Mercado" permite projecao de uma serie APROVADA.')
                ->visible(fn (Get $get): bool => $get('indexer') === PuIndexer::Ipca->value),
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
                ->required(fn (Get $get): bool => $get('indexer') === PuIndexer::Cdi->value)
                ->visible(fn (Get $get): bool => $get('indexer') === PuIndexer::Cdi->value),
            TextInput::make('index_rate_lag_business_days')
                ->label('Defasagem util do CDI')
                ->numeric()
                ->default(1)
                ->required(fn (Get $get): bool => $get('indexer') === PuIndexer::Cdi->value && $get('index_rate_lookup_mode') === PuIndexRateLookupMode::BusinessDayLagExact->value)
                ->visible(fn (Get $get): bool => $get('indexer') === PuIndexer::Cdi->value && $get('index_rate_lookup_mode') === PuIndexRateLookupMode::BusinessDayLagExact->value),
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
            'annual_rate' => $parameter?->getRawOriginal('annual_rate'),
            'indexer' => $parameter?->indexer ?? PuIndexer::Cdi->value,
            'base_index_date' => $parameter?->base_index_date?->toDateString(),
            'index_lag_months' => $parameter?->index_lag_months ?? 2,
            'correction_frequency' => $parameter?->correction_frequency ?? 'monthly',
            'index_projection_policy' => $parameter?->index_projection_policy ?? IpcaProjectionPolicy::PublishedOnly->value,
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
