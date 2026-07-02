<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurvePrerequisiteCheckResult;
use App\Domain\PuCalculator\DTOs\PuCurvePrerequisiteIssue;
use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;

class PuCurvePrerequisiteService
{
    public function __construct(
        private readonly BusinessDayCalendarService $businessDayCalendar,
        private readonly IndexRateService $indexRateService,
        private readonly BusinessCalendarCoverageService $calendarCoverage,
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

        if ($startDate !== null && $endDate !== null && $endDate->gte($startDate)) {
            $this->validateIntegralizationTimeline($issues, $emission, $endDate);
            $this->validateCalendarCoverage($issues, $startDate, $endDate, (string) $parameter->calendar_code, $indexer);

            if ($indexer === PuIndexer::Cdi) {
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
        if ($this->calendarCoverage->ensureCoverage($calendarCode, $startDate, $endDate)) {
            $this->businessDayCalendar->flushCache();
        }

        $missingDates = $this->calendarCoverage->missingDates($calendarCode, $startDate, $endDate);

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

        $this->warnWhenWeekendOnly($issues, $startDate, $endDate, $calendarCode, $indexer);
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
            sprintf(
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
                $lookupDate = $this->requiredIndexLookupDate($parameter, $currentDate);
            } catch (\Throwable) {
                $issues[] = PuCurvePrerequisiteIssue::blocking(
                    'index_rates',
                    sprintf(
                        'Nao foi possivel resolver a defasagem do CDI para a data %s. Revise o calendario e as taxas disponiveis.',
                        $currentDate->toDateString(),
                    ),
                );

                return;
            }

            if ($lookupDate === null) {
                continue;
            }

            $snapshot = match ($parameter->index_rate_lookup_mode_enum) {
                PuIndexRateLookupMode::PreviousAvailableBusinessDay => $this->indexRateService->rateForDate(
                    $parameter->indexer_enum,
                    $currentDate,
                ),
                PuIndexRateLookupMode::PreviousCalendarDayExact,
                PuIndexRateLookupMode::BusinessDayLagExact => $this->indexRateService->exactRateForDate(
                    $parameter->indexer_enum,
                    $lookupDate,
                ),
            };

            if ($snapshot !== null) {
                $lastResolvedDate = $currentDate;

                continue;
            }

            // Offset exato do CDI: a data-alvo além do último CDI publicado é a "cauda futura" da curva.
            // Não bloqueia — a curva é gerada apenas na parte realizada e a parte futura entra sozinha na
            // próxima sincronização. Um buraco DENTRO do período já publicado continua sendo bloqueante.
            if (
                $parameter->index_rate_lookup_mode_enum === PuIndexRateLookupMode::BusinessDayLagExact
                && $lastResolvedDate !== null
                && $this->isBeyondPublishedCdi($lookupDate)
            ) {
                $issues[] = PuCurvePrerequisiteIssue::warning(
                    'index_rates',
                    sprintf(
                        'Somente a parte realizada da curva sera gerada, ate %s. As datas a partir de %s aguardam a publicacao do CDI (lookup em %s) e entrarao automaticamente na proxima sincronizacao do indice.',
                        $lastResolvedDate->toDateString(),
                        $currentDate->toDateString(),
                        $lookupDate->toDateString(),
                    ),
                );

                return;
            }

            $issues[] = PuCurvePrerequisiteIssue::blocking(
                'index_rates',
                sprintf(
                    'Nao existe CDI suficiente para o periodo. Primeira data sem indice resolvido: %s (lookup em %s). Sincronize o CDI publicado (pu:index-rates:sync --indexer=cdi) ou importe-o manualmente.',
                    $currentDate->toDateString(),
                    $lookupDate->toDateString(),
                ),
            );

            return;
        }
    }

    private function isBeyondPublishedCdi(CarbonImmutable $lookupDate): bool
    {
        $lastPublished = IndexRate::query()
            ->forIndexer(PuIndexer::Cdi)
            ->max('rate_date');

        return $lastPublished === null
            || $lookupDate->gt(CarbonImmutable::parse((string) $lastPublished));
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
     * {@see \App\Domain\PuCalculator\Calculators\IpcaCurveCalculator}: para cada data, o mês de
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

    private function requiredIndexLookupDate(EmissionPuParameter $parameter, CarbonImmutable $currentDate): ?CarbonImmutable
    {
        $calendarCode = (string) $parameter->calendar_code;
        $isBusinessDay = $this->businessDayCalendar->isBusinessDay($currentDate, $calendarCode);

        return match ($parameter->index_rate_lookup_mode_enum) {
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => $isBusinessDay ? $currentDate : null,
            PuIndexRateLookupMode::PreviousCalendarDayExact => $this->businessDayCalendar->isBusinessDay($currentDate->subDay(), $calendarCode)
                ? $currentDate->subDay()
                : null,
            PuIndexRateLookupMode::BusinessDayLagExact => $isBusinessDay
                ? $this->businessDayCalendar->shiftBusinessDays(
                    $currentDate,
                    (int) $parameter->index_rate_lag_business_days,
                    $calendarCode,
                )
                : null,
        };
    }
}
