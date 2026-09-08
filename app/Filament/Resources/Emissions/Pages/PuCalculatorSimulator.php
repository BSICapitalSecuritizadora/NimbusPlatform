<?php

declare(strict_types=1);

namespace App\Filament\Resources\Emissions\Pages;

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\DTOs\PuSimulationResult;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Domain\PuCalculator\Services\PuSimulationParameterFactory;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Throwable;

/**
 * Calculadora de PU em modo simulação.
 *
 * A página é uma casca de entrada e apresentação: NENHUMA fórmula financeira
 * vive aqui nem no Blade. Ela coleta hipóteses, chama
 * {@see PuSimulationService::simulate()} -- que delega à engine oficial -- e
 * renderiza o que a engine devolveu.
 *
 * O botão de calcular NÃO depende de readiness operacional: Gate C pendente,
 * candidate inexistente, validação externa ou promoção são irrelevantes aqui.
 */
class PuCalculatorSimulator extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EmissionResource::class;

    protected string $view = 'filament.resources.emissions.pages.pu-calculator';

    protected static ?string $title = 'Calculadora de PU';

    /** Hipóteses do formulário. Vivem só nesta sessão de Livewire. */
    public ?string $firstIntegralizationDate = null;

    public ?string $simulationEndDate = null;

    public ?string $quantity = null;

    public ?string $focusDate = null;

    /** @var array<string, string|null> */
    public array $overrides = [];

    public ?string $selectedCurveDate = null;

    public bool $businessDaysOnly = false;

    public bool $paymentsOnly = false;

    public bool $hasCalculated = false;

    private ?PuSimulationResult $result = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::canAccess(['record' => $this->record]), 403);

        $this->overrides = array_fill_keys(PuSimulationParameterFactory::CONFIGURATION_FIELDS, null);
        $this->hydrateDefaultsFromBaseline();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can(AccessPermission::PuCurveView->value) ?? false;
    }

    public function getTitle(): string
    {
        return 'Calculadora de PU — Simulação';
    }

    public function getSubheading(): ?string
    {
        return 'Execute a engine oficial de PU sobre hipóteses, sem qualquer efeito operacional.';
    }

    /**
     * Pré-carrega apenas o que a baseline/parâmetro já comprova. Campos sem
     * origem documental permanecem vazios: nada é preenchido silenciosamente.
     */
    private function hydrateDefaultsFromBaseline(): void
    {
        $resolution = app(PuSimulationService::class)->resolveParameters(
            $this->getRecord(),
            new PuSimulationInput,
        );

        $contractualEnd = $resolution['values']['contractual_curve_end_date'] ?? null;

        if (is_string($contractualEnd)) {
            $this->simulationEndDate = $contractualEnd;
        }

        $start = $resolution['values']['curve_start_date'] ?? null;

        if (is_string($start)) {
            $this->firstIntegralizationDate = $start;
        }
    }

    public function simulationInput(): PuSimulationInput
    {
        return new PuSimulationInput(
            firstIntegralizationDate: $this->date($this->firstIntegralizationDate),
            simulationEndDate: $this->date($this->simulationEndDate),
            quantity: $this->normalizedQuantity(),
            overrides: $this->overrides,
            focusDate: $this->date($this->focusDate),
        );
    }

    /**
     * Resolução dos parâmetros sem calcular. Alimenta o painel de parâmetros e a
     * marcação de origem em toda renderização.
     *
     * @return array<string, mixed>
     */
    public function parameterResolution(): array
    {
        return app(PuSimulationService::class)->resolveParameters(
            $this->getRecord(),
            $this->simulationInput(),
        );
    }

    public function calculate(): void
    {
        $this->result = null;

        try {
            $this->result = app(PuSimulationService::class)->simulate(
                $this->getRecord(),
                $this->simulationInput(),
            );
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Não foi possível concluir a simulação.')
                ->body('Revise os parâmetros informados e tente novamente.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->hasCalculated = true;
        $this->selectedCurveDate = $this->result->selectedRow?->date->toDateString();

        if ($this->result->state === PuSimulationState::Calculated) {
            Notification::make()
                ->title('Simulação calculada.')
                ->body($this->result->reason.' Nenhuma curva oficial foi criada.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title($this->result->state->label())
            ->body($this->result->reason)
            ->warning()
            ->persistent()
            ->send();
    }

    public function result(): ?PuSimulationResult
    {
        if ($this->result === null && $this->hasCalculated) {
            // Uma interação de Livewire posterior ao cálculo (abrir memória,
            // trocar filtro) precisa do mesmo resultado. Recalcular sobre as
            // mesmas hipóteses é determinístico e continua sem escrever nada.
            $this->result = app(PuSimulationService::class)->simulate(
                $this->getRecord(),
                $this->simulationInput(),
            );
        }

        return $this->result;
    }

    public function selectCurveDate(string $date): void
    {
        $this->selectedCurveDate = $date;
    }

    public function clearSelectedCurveDate(): void
    {
        $this->selectedCurveDate = null;
    }

    /**
     * Linhas efetivamente renderizadas na tabela, conforme os filtros de leitura.
     *
     * @return list<PuDailyCurveRowData>
     */
    public function visibleRows(): array
    {
        $result = $this->result();

        if (! $result instanceof PuSimulationResult) {
            return [];
        }

        $rows = $result->rows;

        if ($this->paymentsOnly) {
            $rows = $result->paymentRows();
        } elseif ($this->businessDaysOnly) {
            $rows = array_values(array_filter($rows, fn ($row): bool => $row->isBusinessDay));
        }

        return $rows;
    }

    public function selectedRow(): ?PuDailyCurveRowData
    {
        $result = $this->result();

        if (! $result instanceof PuSimulationResult) {
            return null;
        }

        return $this->selectedCurveDate === null
            ? $result->selectedRow
            : ($result->rowForDate($this->selectedCurveDate) ?? $result->selectedRow);
    }

    /**
     * Sincroniza APENAS as taxas exigidas pela janela simulada.
     *
     * É uma ação explícita e separada do cálculo: `calculate()` nunca consulta o
     * Banco Central. A única tabela escrita é `index_rates`, pela infraestrutura
     * já homologada, com política de não sobrescrever valor divergente.
     */
    public function syncRequiredRatesAction(): Action
    {
        return Action::make('syncRequiredRates')
            ->label('Sincronizar taxas CDI necessárias')
            ->icon('heroicon-o-cloud-arrow-down')
            ->color('warning')
            ->visible(fn (): bool => (auth()->user()?->can(AccessPermission::PuIndexSync->value) ?? false)
                && $this->result()?->state === PuSimulationState::RatesMissing)
            ->requiresConfirmation()
            ->modalHeading('Sincronizar taxas oficiais necessárias')
            ->modalDescription('Serão consultadas e armazenadas as taxas oficiais necessárias para esta simulação. Nenhuma curva, parâmetro, evento ou evidência da emissão será alterado.')
            ->modalSubmitActionLabel('Sincronizar')
            ->action(function (): void {
                $result = $this->result();
                $missing = $result?->missingRateDates ?? [];

                if ($missing === []) {
                    Notification::make()
                        ->title('Nenhuma taxa pendente.')
                        ->info()
                        ->send();

                    return;
                }

                $resolution = $this->parameterResolution();
                $parameter = $resolution['parameter'];

                if (! $parameter instanceof EmissionPuParameter) {
                    Notification::make()
                        ->title('Parâmetros incompletos.')
                        ->body('Complete os parâmetros da simulação antes de sincronizar taxas.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $sync = app(IndexRateSyncService::class)->sync(
                        indexer: $parameter->indexer_enum,
                        from: CarbonImmutable::parse($missing[0])->startOfDay(),
                        to: CarbonImmutable::parse($missing[array_key_last($missing)])->startOfDay(),
                        dryRun: false,
                        userId: auth()->id(),
                        overwritePolicy: IndexRateSyncService::POLICY_SKIP,
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Não foi possível sincronizar as taxas.')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $this->result = null;
                $this->calculate();

                Notification::make()
                    ->title('Sincronização concluída.')
                    ->body(sprintf(
                        '%d taxa(s) consultada(s), %d criada(s), %d mantida(s). Apenas o histórico de índices foi alterado.',
                        $sync->fetched,
                        $sync->created,
                        $sync->skipped,
                    ))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToEmission')
                ->label('Voltar para a Emissão')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => EmissionResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    /** @return array<string, string> */
    public function indexerOptions(): array
    {
        $options = [];

        foreach (PuIndexer::cases() as $indexer) {
            $options[$indexer->value] = $indexer->label();
        }

        return $options;
    }

    private function normalizedQuantity(): ?string
    {
        $raw = trim((string) $this->quantity);

        if ($raw === '') {
            return null;
        }

        // Entrada monetária/numérica brasileira: milhar com ponto, decimal com
        // vírgula. Convertida para string decimal canônica -- nunca para float.
        $normalized = str_replace(['.', ' '], '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        return preg_match('/^\d+(\.\d+)?$/', $normalized) === 1 ? $normalized : null;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
