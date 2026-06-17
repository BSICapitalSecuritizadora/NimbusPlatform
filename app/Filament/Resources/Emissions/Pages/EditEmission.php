<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Actions\Emissions\ValidatePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Schemas\EmissionForm;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\EmissionPuDailyCurve;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;

class EditEmission extends EditRecord
{
    protected static string $resource = EmissionResource::class;

    protected static ?string $title = 'Editar Emissão';

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
                ->label('Configurar Cálculo de PU')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('emissions.update') ?? false)
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalHeading('Configurar Cálculo de PU')
                ->fillForm(fn (): array => $this->getPuCalculationDefaults())
                ->form($this->getPuCalculationForm())
                ->action(function (array $data): void {
                    $this->getRecord()->puParameter()->updateOrCreate(
                        [],
                        $data,
                    );

                    $this->getRecord()->unsetRelation('puParameter');
                    $this->getRecord()->load('puParameter');

                    Notification::make()
                        ->title('Parâmetros do cálculo de PU atualizados.')
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
                ->modalDescription('A curva diaria sera recalculada em segundo plano e persistida como uma nova versao, preservando as anteriores.')
                ->action(function (): void {
                    if ($this->isGeneratingPuCurve || Cache::get($this->puCurveGenerationStatusCacheKey()) === 'processing') {
                        Notification::make()
                            ->title('Geracao ja em andamento.')
                            ->body('Aguarde a conclusao da geracao atual antes de iniciar uma nova.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $emission = $this->getRecord()->loadMissing('puParameter');

                    if ($emission->puParameter === null) {
                        Notification::make()
                            ->title('Configure o calculo de PU antes de gerar a curva.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Cache::put($this->puCurveGenerationStatusCacheKey(), 'processing', 1800);
                    $this->isGeneratingPuCurve = true;

                    GeneratePuDailyCurveJob::dispatch($emission->id);

                    Notification::make()
                        ->title('Geracao da curva iniciada.')
                        ->body('O calculo foi enviado para a fila e a pagina sera atualizada automaticamente ao concluir.')
                        ->info()
                        ->send();
                }),
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
                ])
                ->form([
                    Select::make('calculation_version')
                        ->label('Versão da curva')
                        ->options($this->getCalculationVersionOptions())
                        ->placeholder('Usar a versão mais recente'),
                    Select::make('reference_spreadsheet')
                        ->label('Planilha de referência')
                        ->options(app(PuValidationSpreadsheetLocatorService::class)->options())
                        ->searchable()
                        ->placeholder('Selecione uma planilha de validação'),
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
                            ->title('Validação não executada.')
                            ->body('Selecione uma planilha de referência ou envie um arquivo .xlsx.')
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $report = app(ValidatePuDailyCurve::class)->handle(
                        $this->getRecord(),
                        $spreadsheetPath,
                        $data['calculation_version'] ?? null,
                    );

                    $summaryLines = [
                        sprintf('Linhas comparadas: %d', $report->totalRowsCompared),
                        sprintf('Divergências: %d', $report->totalDivergences),
                        sprintf('Maior diferença de PU: %s', $report->largestPuDifference),
                        sprintf('Maior diferença de valor total: %s', $report->largestTotalValueDifference),
                        sprintf('Maior diferença de pagamento: %s', $report->largestPaymentDifference),
                    ];

                    $divergentLines = collect($report->divergentRows(5))
                        ->map(fn ($row): string => sprintf(
                            '%s: %s',
                            $row->date->format('d/m/Y'),
                            implode('; ', array_keys($row->differences)),
                        ))
                        ->all();

                    $notification = Notification::make()
                        ->title($report->status->value === 'approved' ? 'Validação aprovada.' : 'Validação reprovada.')
                        ->body(implode("\n", array_merge($summaryLines, $divergentLines)))
                        ->persistent();

                    if ($report->status->value === 'approved') {
                        $notification->success();
                    } else {
                        $notification->danger();
                    }

                    $notification->send();
                }),
            DeleteAction::make()
                ->label('Excluir Emissão')
                ->modalHeading('Excluir Emissão'),
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
                ->title('Cláusulas extraídas com sucesso')
                ->body('Os campos foram preenchidos com os dados extraídos do Termo de Securitização. Revise e salve.')
                ->success()
                ->send();

            $this->redirect(
                EmissionResource::getUrl('edit', ['record' => $this->record]),
                navigate: true,
            );
        } elseif (is_array($status) && isset($status['error'])) {
            $this->isExtractingClauses = false;
            Cache::forget("gemini_extraction_{$this->record->id}_status");

            Notification::make()
                ->title('Falha na extração das cláusulas')
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
        return 'Emissão atualizada com sucesso.';
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
                ->label('Início da curva')
                ->required(),
            DatePicker::make('curve_end_date')
                ->label('Fim da curva')
                ->required(),
            TextInput::make('initial_unit_value')
                ->label('Valor unitário inicial')
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
                ->label('Base de dias úteis')
                ->numeric()
                ->default(252)
                ->required(),
            TextInput::make('calendar_code')
                ->label('Calendário')
                ->default('B3')
                ->required(),
            Toggle::make('legacy_projection_enabled')
                ->label('Atualizar projeções legadas (payments / pu_histories)')
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
