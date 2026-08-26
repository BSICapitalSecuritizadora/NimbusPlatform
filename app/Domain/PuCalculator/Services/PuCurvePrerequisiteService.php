<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Calculators\IpcaCurveCalculator;
use App\Domain\PuCalculator\DTOs\PuCurvePrerequisiteCheckResult;
use App\Domain\PuCalculator\DTOs\PuCurvePrerequisiteIssue;
use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;

class PuCurvePrerequisiteService
{
    public function __construct(
        private readonly BusinessDayCalendarService $businessDayCalendar,
        private readonly IndexRateService $indexRateService,
        private readonly BusinessCalendarCoverageService $calendarCoverage,
        private readonly PuIndexRateRequirementResolver $indexRateRequirementResolver,
    ) {}

    public function handle(Emission $emission): PuCurvePrerequisiteCheckResult
    {
        $emission->loadMissing(['puParameter', 'puEvents', 'integralizationHistories']);

        $issues = [];
        $parameter = $emission->puParameter;

        if ($parameter === null) {
            return new PuCurvePrerequisiteCheckResult([
                PuCurvePrerequisiteIssue::blocking(
                    'pu_parameter',
                    'Configure os parametros do calculo de PU antes de gerar a curva.',
                ),
            ]);
        }

        $startDate = $parameter->curve_start_date !== null
            ? CarbonImmutable::instance($parameter->curve_start_date)
            : null;
        $endDate = $parameter->curve_end_date !== null
            ? CarbonImmutable::instance($parameter->curve_end_date)
            : null;

        if ($startDate === null) {
            $issues[] = PuCurvePrerequisiteIssue::blocking('curve_start_date', 'Defina a data inicial da curva de PU.');
        }

        if ($endDate === null) {
            $issues[] = PuCurvePrerequisiteIssue::blocking('curve_end_date', 'Defina a data final ou vencimento da curva de PU.');
        }

        if ($startDate !== null && $endDate !== null && $endDate->lt($startDate)) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'curve_range',
                'A data final da curva nao pode ser anterior a data inicial.',
            );
        }

        if (bccomp((string) ($parameter->initial_unit_value ?? '0'), '0', DecimalRounder::UNIT_SCALE) <= 0) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'initial_unit_value',
                'Informe um PU inicial maior que zero.',
            );
        }

        $indexer = $parameter->indexer_enum;

        if ($indexer === PuIndexer::Ipca) {
            if ($parameter->base_index_date === null) {
                $issues[] = PuCurvePrerequisiteIssue::blocking(
                    'base_index_date',
                    'Informe a data-base do índice (base_index_date) usada no aniversário de correção do IPCA.',
                );
            }

            if (bccomp((string) ($parameter->annual_rate ?? '0'), '0', DecimalRounder::RATE_SCALE) <= 0) {
                $issues[] = PuCurvePrerequisiteIssue::blocking(
                    'annual_rate',
                    'Informe a taxa real anual (cupom) maior que zero para a operação IPCA.',
                );
            }
        }

        if ($indexer->usesSpread() && $parameter->spread_rate === null) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'spread_rate',
                'Informe o spread anual da operacao.',
            );
        }

        if ($indexer->usesAnnualRate() && bccomp((string) ($parameter->annual_rate ?? '0'), '0', DecimalRounder::RATE_SCALE) <= 0) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'annual_rate',
                'Informe a taxa prefixada anual maior que zero.',
            );
        }

        if ((int) $parameter->business_day_basis <= 0) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'business_day_basis',
                'Informe uma base valida de dias uteis para o calculo.',
            );
        }

        if (! filled($parameter->calendar_code)) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'calendar_code',
                'Informe o calendario de dias uteis utilizado na curva.',
            );
        }

        $premiumConfigurationIsValid = $this->validateFirstCouponPreIntegralizationPremiumConfiguration(
            $issues,
            $parameter,
            $startDate,
        );

        if ($startDate !== null && $endDate !== null && $endDate->gte($startDate)) {
            $this->validateIntegralizationTimeline($issues, $emission, $endDate);
            $financialRequirementStartDate = $premiumConfigurationIsValid
                ? $this->firstCouponFinancialRequirementStartDate($issues, $parameter, $startDate)
                : $startDate;
            $this->validateCalendarCoverage(
                $issues,
                $financialRequirementStartDate,
                $endDate,
                (string) $parameter->calendar_code,
                $indexer,
            );

            if ($parameter->hasFirstCouponPreIntegralizationPremium()) {
                $this->validateFirstCouponIntegralizationAndEvent($issues, $emission, $startDate);
            }

            if ($indexer === PuIndexer::Cdi) {
                if ($premiumConfigurationIsValid) {
                    $this->validateFirstCouponPreIntegralizationIndexCoverage($issues, $parameter);
                }

                $this->validateIndexCoverage($issues, $parameter, $startDate, $endDate);
            }

            if ($indexer === PuIndexer::Ipca && $parameter->base_index_date !== null) {
                $this->validateIpcaIndexCoverage($issues, $parameter, $startDate, $endDate);
            }
        }

        if ($emission->puEvents->isEmpty()) {
            $issues[] = PuCurvePrerequisiteIssue::warning(
                'pu_events',
                'Nenhum evento de juros ou amortizacao foi cadastrado. A curva sera gerada sem pagamentos.',
            );
        }

        return new PuCurvePrerequisiteCheckResult($issues);
    }

    /** @param  list<PuCurvePrerequisiteIssue>  $issues */
    private function validateFirstCouponPreIntegralizationPremiumConfiguration(
        array &$issues,
        EmissionPuParameter $parameter,
        ?CarbonImmutable $startDate,
    ): bool {
        if (! $parameter->hasFirstCouponPreIntegralizationPremium()) {
            return true;
        }

        $valid = true;

        if ($parameter->indexer_enum !== PuIndexer::Cdi) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_premium',
                'O prêmio pré-integralização do primeiro cupom está disponível somente para configurações CDI.',
            );
            $valid = false;
        }

        if ((int) $parameter->first_coupon_pre_integralization_business_days <= 0) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_business_days',
                'Informe uma quantidade de Dias Úteis maior que zero para o prêmio pré-integralização.',
            );
            $valid = false;
        }

        if (! (bool) $parameter->first_coupon_pre_integralization_apply_index_factor
            && ! (bool) $parameter->first_coupon_pre_integralization_apply_spread_factor) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_factors',
                'O prêmio pré-integralização deve aplicar ao menos o Fator DI ou o Fator Spread.',
            );
            $valid = false;
        }

        if ($startDate === null) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_start_date',
                'O prêmio pré-integralização exige curve_start_date correspondente à integralização efetiva.',
            );
            $valid = false;
        }

        return $valid;
    }

    /** @param  list<PuCurvePrerequisiteIssue>  $issues */
    private function firstCouponFinancialRequirementStartDate(
        array &$issues,
        EmissionPuParameter $parameter,
        CarbonImmutable $startDate,
    ): CarbonImmutable {
        try {
            return $this->indexRateRequirementResolver
                ->firstCouponPreIntegralizationFinancialCalendarStartDate($parameter) ?? $startDate;
        } catch (\Throwable $exception) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_calendar',
                sprintf(
                    'Não foi possível resolver os Dias Úteis pré-integralização no calendário %s: %s',
                    (string) $parameter->calendar_code,
                    $exception->getMessage(),
                ),
            );

            return $startDate;
        }
    }

    /** @param  list<PuCurvePrerequisiteIssue>  $issues */
    private function validateFirstCouponIntegralizationAndEvent(
        array &$issues,
        Emission $emission,
        CarbonImmutable $startDate,
    ): void {
        $hasIntegralizationAtStart = $emission->integralizationHistories
            ->contains(fn ($history): bool => $history->date !== null
                && CarbonImmutable::instance($history->date)->equalTo($startDate)
                && bccomp((string) $history->quantity, '0', DecimalRounder::QUANTITY_SCALE) === 1);

        if (! $hasIntegralizationAtStart) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_integralization',
                sprintf(
                    'O prêmio exige integralização positiva na curve_start_date %s; confirme documentalmente a data e cadastre o histórico correspondente.',
                    $startDate->toDateString(),
                ),
            );
        }

        $firstInterestEvent = $emission->puEvents
            ->filter(fn ($event): bool => $event->event_type_enum === PuEventType::InterestPayment)
            ->sortBy(fn ($event): string => CarbonImmutable::instance($event->effective_date)->toDateString())
            ->first();

        if ($firstInterestEvent === null) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_interest_event',
                'Cadastre o primeiro evento de pagamento de juros antes de aplicar o prêmio pré-integralização.',
            );

            return;
        }

        if (CarbonImmutable::instance($firstInterestEvent->effective_date)->lte($startDate)) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_interest_event',
                'O primeiro pagamento de juros que recebe o prêmio deve ocorrer após a integralização/curve_start_date.',
            );
        }
    }

    /** @param  list<PuCurvePrerequisiteIssue>  $issues */
    private function validateFirstCouponPreIntegralizationIndexCoverage(
        array &$issues,
        EmissionPuParameter $parameter,
    ): void {
        try {
            $requirements = $this->indexRateRequirementResolver
                ->firstCouponPreIntegralizationRateRequirements($parameter);
        } catch (\Throwable $exception) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_index_rates',
                sprintf('Não foi possível resolver os snapshots DI do prêmio: %s', $exception->getMessage()),
            );

            return;
        }

        foreach ($requirements as $requirement) {
            if ($requirement->rate !== null) {
                continue;
            }

            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'first_coupon_pre_integralization_index_rates',
                "Snapshot DI obrigatório do prêmio pré-integralização ausente.\n\n{$requirement->missingRateMessage()}",
            );
        }
    }

    /**
     * @param  list<PuCurvePrerequisiteIssue>  $issues
     */
    private function validateIntegralizationTimeline(array &$issues, Emission $emission, CarbonImmutable $endDate): void
    {
        $integralizations = $emission->integralizationHistories
            ->filter(fn ($history): bool => $history->date !== null)
            ->filter(fn ($history): bool => CarbonImmutable::instance($history->date)->lte($endDate));

        if ($integralizations->isEmpty()) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'integralization_histories',
                'Cadastre ao menos uma integralizacao valida para definir a quantidade vigente da curva.',
            );

            return;
        }

        $quantity = $integralizations
            ->reduce(
                fn (string $carry, $history): string => bcadd($carry, (string) $history->quantity, DecimalRounder::QUANTITY_SCALE),
                '0.0000',
            );

        if (bccomp($quantity, '0', DecimalRounder::QUANTITY_SCALE) <= 0) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'quantity',
                'A quantidade integralizada acumulada precisa ser maior que zero.',
            );
        }
    }

    /**
     * @param  list<PuCurvePrerequisiteIssue>  $issues
     */
    private function validateCalendarCoverage(
        array &$issues,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        string $calendarCode,
        PuIndexer $indexer,
    ): void {
        $missingDates = $this->calendarCoverage->missingDates($calendarCode, $startDate, $endDate);

        if ($missingDates !== [] && $this->calendarCoverage->ensureCoverage($calendarCode, $startDate, $endDate)) {
            $this->businessDayCalendar->flushCache();
            $missingDates = $this->calendarCoverage->missingDates($calendarCode, $startDate, $endDate);
        }

        if ($missingDates !== []) {
            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'business_calendar_dates',
                sprintf(
                    'O calendario %s nao cobre todo o periodo da curva (faltam %d data(s), a primeira em %s). Complete o calendario com "php artisan pu:business-calendar:seed --calendar=%s --from=%s --to=%s" ou importe/cadastre os dias e feriados manualmente.',
                    $calendarCode,
                    count($missingDates),
                    $missingDates[0],
                    $calendarCode,
                    $startDate->toDateString(),
                    $endDate->toDateString(),
                ),
            );

            return;
        }

        $this->warnWhenCalendarIsNotConfirmed($issues, $startDate, $endDate, $calendarCode);
        $this->warnWhenWeekendOnly($issues, $startDate, $endDate, $calendarCode, $indexer);
    }

    /**
     * @param  list<PuCurvePrerequisiteIssue>  $issues
     */
    private function warnWhenCalendarIsNotConfirmed(
        array &$issues,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        string $calendarCode,
    ): void {
        $unconfirmedYears = array_filter(
            $this->calendarCoverage->annualCoverage($calendarCode, $startDate, $endDate),
            static fn (array $coverage): bool => ! $coverage['confirmed'],
        );

        if ($unconfirmedYears === []) {
            return;
        }

        $details = implode(', ', array_map(
            static fn (array $coverage): string => sprintf('%d (%s)', $coverage['year'], $coverage['state']),
            array_values($unconfirmedYears),
        ));

        $issues[] = PuCurvePrerequisiteIssue::warning(
            'business_calendar_confirmation',
            sprintf(
                'O cálculo continuará por compatibilidade, mas o calendário %s possui ano(s) não confirmado(s): %s. Revise a cobertura e confirme a fonte oficial antes de tratar o resultado como definitivo.',
                $calendarCode,
                $details,
            ),
        );
    }

    /**
     * Aviso (nao bloqueante): o calendario cobre o periodo, mas nao ha feriados cadastrados (derivacao
     * weekend-only). Para CDI/Prefixado isso reduz a precisao na base 252 em curvas longas, pois esses
     * indexadores contam dias uteis pelo calendario. O IPCA nao recebe o aviso porque sua engine apura
     * DUP/DUT apenas por fim de semana (feriados nao alteram o gabarito validado).
     *
     * @param  list<PuCurvePrerequisiteIssue>  $issues
     */
    private function warnWhenWeekendOnly(
        array &$issues,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        string $calendarCode,
        PuIndexer $indexer,
    ): void {
        if (! in_array($indexer, [PuIndexer::Cdi, PuIndexer::Prefixed], true)) {
            return;
        }

        if (! $this->calendarCoverage->isWeekendOnly($calendarCode, $startDate, $endDate)) {
            return;
        }

        $issues[] = PuCurvePrerequisiteIssue::warning(
            'business_calendar_holidays',
            BusinessCalendarRegistry::normalize($calendarCode) === BusinessCalendarRegistry::B3_LISTED_TRADING
                ? 'O calendário B3_LISTED_TRADING está apenas com finais de semana. Cadastre e confirme o calendário oficial de sessões da B3; o arquivo ANBIMA bancário não é aplicável a este calendário.'
                : sprintf(
                    'O calendario %s esta apenas com fins de semana (nenhum feriado importado para o periodo da curva). Para maxima precisao na base 252, clique em "Importar feriados ANBIMA" ou rode "php artisan pu:holidays:import-anbima --calendar=%s".',
                    $calendarCode,
                    $calendarCode,
                ),
        );
    }

    /**
     * @param  list<PuCurvePrerequisiteIssue>  $issues
     */
    private function validateIndexCoverage(
        array &$issues,
        EmissionPuParameter $parameter,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): void {
        $lastResolvedDate = null;

        for ($currentDate = $startDate->addDay(); $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            try {
                $rateRequirement = $this->indexRateRequirementResolver->resolve($parameter, $currentDate);
            } catch (\Throwable) {
                $issues[] = PuCurvePrerequisiteIssue::blocking(
                    'index_rates',
                    sprintf(
                        'Nao foi possivel resolver a Taxa DI requerida para a data da curva %s. Modo: %s. Calendario: %s. Lag: %d dia(s) util(eis). Revise o calendario e as taxas disponiveis.',
                        $currentDate->toDateString(),
                        $parameter->index_rate_lookup_mode_enum->name,
                        (string) $parameter->calendar_code,
                        (int) $parameter->index_rate_lag_business_days,
                    ),
                );

                return;
            }

            if (! $rateRequirement->isRequiredForCalculation()) {
                continue;
            }

            $snapshot = $rateRequirement->rate;
            $lookupDate = $rateRequirement->requiredRateDate();

            if ($snapshot !== null) {
                $lastResolvedDate = $currentDate;

                continue;
            }

            // Offset exato do CDI: a data-alvo além do último CDI publicado é a "cauda futura" da curva.
            // Não bloqueia — a curva é gerada apenas na parte realizada e a parte futura entra sozinha na
            // próxima sincronização. Um buraco DENTRO do período já publicado continua sendo bloqueante.
            if (
                $parameter->index_rate_lookup_mode_enum === PuIndexRateLookupMode::BusinessDayLagExact
                && $this->indexRateRequirementResolver->isAwaitingPublication(
                    $parameter,
                    $rateRequirement,
                    $lastResolvedDate !== null,
                )
            ) {
                $issues[] = PuCurvePrerequisiteIssue::warning(
                    'index_rates',
                    sprintf(
                        "Somente a parte realizada da curva sera gerada, ate %s. As datas a partir de %s aguardam a publicacao do CDI (lookup em %s) e entrarao automaticamente na proxima sincronizacao do indice.\n\nModo: %s\nRegra: %s\nData requerida: %s",
                        $lastResolvedDate->toDateString(),
                        $currentDate->toDateString(),
                        $lookupDate?->toDateString() ?? 'não resolvida',
                        $rateRequirement->lookupMode->name,
                        $rateRequirement->ruleDescription(),
                        $lookupDate?->toDateString() ?? 'não resolvida',
                    ),
                );

                return;
            }

            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'index_rates',
                "Nao existe CDI suficiente para o periodo.\n\n{$rateRequirement->missingRateMessage()}\n\nSincronize o CDI publicado (pu:index-rates:sync --indexer=cdi) ou importe-o manualmente.",
            );

            return;
        }
    }

    /**
     * Cobertura de número-índice IPCA até o vencimento: para cada mês de referência exigido pela curva,
     * precisa existir IPCA PUBLICADO ou, sob a política de mercado, IPCA PROJETADO de uma SÉRIE APROVADA.
     * Mês ausente, projeção não permitida pela política ou série projetada não aprovada bloqueiam a geração
     * com mensagem clara (a curva nunca projeta silenciosamente nem usa projeção não aprovada).
     *
     * @param  list<PuCurvePrerequisiteIssue>  $issues
     */
    private function validateIpcaIndexCoverage(
        array &$issues,
        EmissionPuParameter $parameter,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): void {
        $policy = IpcaProjectionPolicy::fromParameter($parameter->index_projection_policy);

        foreach ($this->requiredIpcaReferenceMonths($parameter, $startDate, $endDate) as $monthKey => $referenceMonth) {
            $rate = $this->indexRateService->exactRateForDate(PuIndexer::Ipca, $referenceMonth);

            if ($rate === null) {
                $issues[] = PuCurvePrerequisiteIssue::blocking(
                    'index_rates',
                    sprintf(
                        'Não há número-índice IPCA (publicado ou projetado) cadastrado para o mês de referência %s. Sincronize o IPCA publicado (pu:index-rates:sync --indexer=ipca) ou importe-o; para meses futuros, cadastre/aprove a série projetada.',
                        $monthKey,
                    ),
                );

                return;
            }

            if (! $rate->isProjected) {
                continue;
            }

            if (! $policy->allowsProjection()) {
                $issues[] = PuCurvePrerequisiteIssue::blocking(
                    'index_projection_policy',
                    sprintf(
                        'O mês de referência %s só possui IPCA PROJETADO, mas a política de projeção atual (%s) não permite projeção. Configure "market" e aprove a série projetada.',
                        $monthKey,
                        $policy->label(),
                    ),
                );

                return;
            }

            if (! $rate->isApprovedForOperationalUse()) {
                $issues[] = PuCurvePrerequisiteIssue::blocking(
                    'index_projection_series',
                    sprintf(
                        'O IPCA projetado do mês de referência %s não pertence a uma série projetada APROVADA (status: %s). Aprove a série projetada via maker/checker antes de gerar a curva.',
                        $monthKey,
                        $rate->projectionSeriesStatus ?? 'sem série vinculada',
                    ),
                );

                return;
            }
        }
    }

    /**
     * Conjunto distinto de meses de referência exigidos pela curva IPCA. Espelha a mecânica do
     * {@see IpcaCurveCalculator}: para cada data, o mês de
     * referência é o 1º dia do mês da abertura do aniversário, defasado de `index_lag_months`; a razão
     * de correção também exige o mês imediatamente anterior.
     *
     * @return array<string, CarbonImmutable> indexado pelo `YYYY-MM-DD` do 1º dia do mês
     */
    private function requiredIpcaReferenceMonths(
        EmissionPuParameter $parameter,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $anniversaryDay = (int) CarbonImmutable::instance($parameter->base_index_date)->day;
        $lagMonths = (int) ($parameter->index_lag_months ?? 0);

        $months = [];

        for ($currentDate = $startDate; $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            $closing = $currentDate->day <= $anniversaryDay
                ? $currentDate->day($anniversaryDay)
                : $currentDate->addMonthNoOverflow()->day($anniversaryDay);
            $openingAnniversary = $closing->subMonthNoOverflow()->day($anniversaryDay);

            $referenceMonth = $openingAnniversary->startOfMonth()->subMonthsNoOverflow($lagMonths);
            $previousMonth = $referenceMonth->subMonthNoOverflow();

            $months[$referenceMonth->toDateString()] = $referenceMonth;
            $months[$previousMonth->toDateString()] = $previousMonth;
        }

        ksort($months);

        return $months;
    }
}
