<?php

declare(strict_types=1);

namespace App\Filament\Resources\Emissions\Pages;

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\DTOs\PuSimulationResult;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Domain\PuCalculator\Services\PuSimulationParameterFactory;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
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

    /**
     * HIPÓTESE de calendário de observação do CDI. Vive só nesta sessão de
     * Livewire: não é persistida, não vira evidência e some no refresh.
     */
    public ?string $indexRateCalendarCode = null;

    /**
     * HIPÓTESE de calendário de accrual da curva. Vive só nesta sessão de
     * Livewire: não é persistida, não altera `EmissionPuParameter` e some no
     * refresh. Decide apenas Dia Útil, DUP/DUT e fator diário da curva; eventos,
     * convenção Following e datas de pagamento seguem no calendário contratual.
     */
    public ?string $accrualCalendarCode = null;

    /**
     * PERFIL DE CÁLCULO da simulação. Nasce contratual, vive só nesta sessão de
     * Livewire e não é persistido em lugar nenhum: nem em `EmissionPuParameter`,
     * nem em curva, candidate, promoção ou homologação. Trocar o perfil não
     * escreve nada -- apenas muda o que esta tela calcula e exibe.
     */
    public string $calculationProfile = 'contractual';

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

        $start = $resolution['values']['curve_start_date'] ?? null;

        if (is_string($start)) {
            $this->firstIntegralizationDate = $start;
        }

        // A data final NÃO é pré-preenchida, e nenhuma outra data serve de
        // palpite para ela. O vencimento contratual é o fim do INSTRUMENTO, não
        // o fim de uma simulação: usá-lo como recorte fazia a janela nascer com
        // cinco anos e o plano de taxas exigir mais de mil divulgações de CDI
        // para responder a uma pergunta de poucas semanas. O recorte é decisão
        // explícita de quem simula, e `calculate()` recusa calcular sem ele.
        $this->simulationEndDate = null;
    }

    public function simulationInput(): PuSimulationInput
    {
        return new PuSimulationInput(
            firstIntegralizationDate: $this->date($this->firstIntegralizationDate),
            simulationEndDate: $this->date($this->simulationEndDate),
            quantity: $this->normalizedQuantity(),
            overrides: $this->overrides,
            focusDate: $this->date($this->focusDate),
            indexRateCalendarCode: $this->indexRateCalendarCode,
            accrualCalendarCode: $this->accrualCalendarCode,
            calculationProfile: $this->calculationProfile(),
        );
    }

    /**
     * Perfil escolhido, resolvido pelo enum. Valor ausente, vazio ou
     * desconhecido devolve o contratual: o legado exige escolha explícita e
     * válida, nunca um estado de formulário malformado.
     */
    public function calculationProfile(): PuCalculationProfile
    {
        return PuCalculationProfile::fromNullable($this->calculationProfile);
    }

    /**
     * Perfis oferecidos na tela, com rótulo e descrição.
     *
     * @return list<array{value:string, label:string, description:string}>
     */
    public function calculationProfileOptions(): array
    {
        return array_map(
            fn (PuCalculationProfile $profile): array => [
                'value' => $profile->value,
                'label' => $profile->label(),
                'description' => $profile->description(),
            ],
            PuCalculationProfile::cases(),
        );
    }

    /** Aviso do perfil ativo. Nulo no contratual. */
    public function calculationProfileWarning(): ?string
    {
        return $this->calculationProfile()->warning();
    }

    /** Aviso adicional para a linha do primeiro cupom. Nulo no contratual. */
    public function calculationProfileFirstCouponWarning(): ?string
    {
        return $this->calculationProfile()->firstCouponWarning();
    }

    /**
     * Trocar de perfil invalida o resultado exibido: a curva na tela passa a
     * não corresponder ao perfil selecionado. Nada é gravado -- apenas o
     * resultado em memória é descartado até um novo cálculo.
     */
    public function updatedCalculationProfile(): void
    {
        $this->result = null;
        $this->hasCalculated = false;
    }

    /**
     * Vencimento contratual resolvido, em formato brasileiro.
     *
     * Existe para a tela poder citar o vencimento SEM confundi-lo com o recorte
     * da simulação -- são semânticas distintas e continuam separadas.
     */
    public function contractualMaturityLabel(): ?string
    {
        $maturity = $this->parameterResolution()['values']['contractual_curve_end_date'] ?? null;

        return is_string($maturity) && $maturity !== ''
            ? CarbonImmutable::parse($maturity)->format('d/m/Y')
            : null;
    }

    /**
     * Calendários oferecidos como hipótese de observação do índice.
     *
     * A opção vazia é o padrão e significa "o mesmo calendário contratual da
     * curva" -- nenhuma mudança silenciosa de semântica.
     *
     * @return array<string, string>
     */
    public function indexRateCalendarOptions(): array
    {
        return ['' => 'Mesmo calendário da curva (contratual)'] + BusinessCalendarRegistry::options();
    }

    /**
     * Calendários oferecidos como hipótese de accrual da curva.
     *
     * A opção vazia é o padrão e significa "o mesmo calendário contratual" --
     * nenhuma emissão muda de calendário por abrir esta tela.
     *
     * @return array<string, string>
     */
    public function accrualCalendarOptions(): array
    {
        return [
            '' => 'Mesmo calendário contratual',
            BusinessCalendarRegistry::BR_FINANCIAL_MARKET => 'Mercado financeiro — FEBRABAN/ANBIMA',
        ];
    }

    /** Rótulo da hipótese de accrual, ou null quando não há hipótese. */
    public function accrualCalendarOverride(): ?string
    {
        $code = $this->simulationInput()->accrualCalendarCode();

        return $code === null || $code === $this->curveCalendarCode() ? null : $code;
    }

    /**
     * Calendário contratual efetivamente resolvido para a curva, para a tela
     * poder contrastá-lo com a hipótese de observação.
     */
    public function curveCalendarCode(): ?string
    {
        $code = $this->parameterResolution()['values']['calendar_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** Rótulo da hipótese de observação, ou null quando não há hipótese. */
    public function indexRateCalendarOverride(): ?string
    {
        $code = $this->simulationInput()->indexRateCalendarCode();

        return $code === null || $code === $this->curveCalendarCode() ? null : $code;
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

        // Sem recorte informado não há simulação a fazer. A alternativa seria
        // adivinhar uma janela, e a única data "óbvia" disponível -- o
        // vencimento contratual -- é justamente a que não pode ocupar esse
        // lugar: ela descreve o instrumento, não a pergunta.
        if ($this->date($this->simulationEndDate) === null) {
            Notification::make()
                ->title('Informe a data final da simulação.')
                ->body(sprintf(
                    'A simulação não assume uma janela. Escolha até quando ela deve correr — o vencimento contratual (%s) é o fim do instrumento, e não o recorte desta simulação.',
                    $this->contractualMaturityLabel() ?? 'não resolvido',
                ))
                ->warning()
                ->persistent()
                ->send();

            return;
        }

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

                // A janela pedida ao Banco Central é a MENOR que cobre as datas
                // efetivamente ausentes -- nunca a emissão inteira. A ordenação
                // é defensiva: `from > to` produziria zero blocos no cliente
                // SGS, isto é, uma "sincronização" que nunca sai da máquina.
                sort($missing);
                $from = CarbonImmutable::parse($missing[0])->startOfDay();
                $to = CarbonImmutable::parse($missing[array_key_last($missing)])->startOfDay();

                try {
                    $sync = app(IndexRateSyncService::class)->sync(
                        indexer: $parameter->indexer_enum,
                        from: $from,
                        to: $to,
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

                // Recalcula ANTES de julgar o resultado: quem decide se a
                // sincronização resolveu o problema é a própria simulação, não
                // o contador de linhas gravadas.
                $this->result = null;
                $this->calculate();
                $stillMissing = array_values(array_intersect($missing, $this->result()?->missingRateDates ?? []));

                // Nenhum bloco consultado significa que a janela não chegou ao
                // Banco Central. Isso é defeito do fluxo, e não sucesso com
                // zero taxas: reportar "concluída" aqui esconde justamente o
                // caso que precisa ser investigado.
                if ($sync->blocksTotal === 0) {
                    Notification::make()
                        ->title('A sincronização não consultou o Banco Central.')
                        ->body(sprintf(
                            'Nenhuma janela de consulta foi montada para %s. Nenhuma taxa foi buscada e nada foi gravado.',
                            $this->formatDateList($missing),
                        ))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                if ($stillMissing !== []) {
                    Notification::make()
                        ->title('Não foi possível obter todas as taxas necessárias.')
                        ->body(trim(sprintf(
                            "A consulta ao Banco Central foi feita (%d taxa(s) retornada(s), %d criada(s), %d mantida(s)), mas continua(m) ausente(s): %s.\n%s",
                            $sync->fetched,
                            $sync->created,
                            $sync->skipped,
                            $this->formatDateList($stillMissing),
                            implode(' ', $sync->errors),
                        )))
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

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

    /**
     * Lista de datas em formato brasileiro, para as mensagens da ação de sync.
     *
     * @param  list<string>  $dates
     */
    private function formatDateList(array $dates): string
    {
        return implode(', ', array_map(
            fn (string $date): string => CarbonImmutable::parse($date)->format('d/m/Y'),
            $dates,
        ));
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
