<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuBaselineCandidate;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\DTOs\PuSimulationResult;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IntegralizationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

/**
 * Calculadora de PU em modo simulação.
 *
 * Esta classe é um ADAPTER, não uma calculadora: nenhuma fórmula financeira
 * vive aqui. Ela monta um cenário em memória e delega ao mesmo
 * `PuCurveGeneratorService` usado pela geração operacional e pela homologação
 * numérica, exatamente no formato já homologado por
 * {@see PuCandidateCurveService::generate()} -- `clone` da emissão,
 * `setRelation()` dos insumos e uma única chamada à engine.
 *
 * Consequências deliberadas:
 *  - zero escrita: nada de parâmetro, evento, curva, versão, evidência,
 *    benchmark, validação externa ou promoção;
 *  - zero fetch externo: `simulate()` é determinístico sobre os dados locais e
 *    nunca consulta o Banco Central;
 *  - zero fallback de taxa: a ausência da taxa exigida é reportada, não
 *    contornada por taxa anterior, próxima, interpolada ou mais recente;
 *  - readiness operacional NÃO bloqueia: Gate C pendente, candidate inexistente
 *    ou promoção indisponível são irrelevantes aqui. Só impede simular o que é
 *    matematicamente impeditivo.
 */
final class PuSimulationService
{
    /**
     * Teto de janela simulada. Cobre com folga uma emissão de 2026 a 2031
     * inteira, e ainda evita que uma data digitada por engano dispare décadas
     * de iteração diária.
     */
    public const MAX_WINDOW_YEARS = 10;

    public function __construct(
        private readonly PuSimulationParameterFactory $parameters,
        private readonly PuBaselineEventRequirementService $eventRequirements,
        private readonly PuIndexSnapshotPlanService $ratePlans,
        private readonly PuIndexRateRequirementResolver $rateRequirements,
        private readonly BusinessCalendarCoverageService $calendarCoverage,
        private readonly PuCurveGeneratorService $curveGenerator,
        private readonly IndexRateLookupService $indexRateLookup,
        // Usado EXCLUSIVAMENTE por `publishedSource()`, que é leitura de
        // configuração da série homologada (`bcb_sgs:4389`). `simulate()` nunca
        // chama `sync()` nem qualquer caminho de fetch: a sincronização é uma
        // ação separada e explícita da interface.
        private readonly IndexRateSyncService $rateSync,
        private readonly FirstCouponPreIntegralizationPremiumCalculator $premiumCalculator,
    ) {}

    public function simulate(Emission $emission, PuSimulationInput $input): PuSimulationResult
    {
        $resolution = $this->parameters->resolve($emission, $input);
        $values = $resolution['values'];
        $origins = $resolution['origins'];

        if ($resolution['parameter'] === null) {
            return new PuSimulationResult(
                state: PuSimulationState::MissingInput,
                reason: 'Informe os parâmetros obrigatórios para simular.',
                input: $input,
                parameters: $values,
                origins: $origins,
                missingFields: $resolution['missing'],
                conflictingRates: [],
            );
        }

        /** @var EmissionPuParameter $parameter */
        $parameter = $resolution['parameter'];
        $startDate = CarbonImmutable::instance($parameter->curve_start_date)->startOfDay();
        $endDate = CarbonImmutable::instance($parameter->curve_end_date)->startOfDay();

        if ($endDate->lt($startDate)) {
            return $this->failure(
                PuSimulationState::MissingInput,
                'A data final da simulação não pode ser anterior à data inicial.',
                $input,
                $resolution,
                $startDate,
                $endDate,
            );
        }

        if ($startDate->diffInYears($endDate) > self::MAX_WINDOW_YEARS) {
            return $this->failure(
                PuSimulationState::MissingInput,
                sprintf(
                    'A janela simulada excede %d anos. Selecione um período menor.',
                    self::MAX_WINDOW_YEARS,
                ),
                $input,
                $resolution,
                $startDate,
                $endDate,
            );
        }

        $calendarStart = $this->calendarStartDate($parameter, $startDate);
        $calendar = $this->calendarDiagnostics($parameter, $calendarStart, $endDate);

        if (! $calendar['resolvable']) {
            return $this->failure(
                PuSimulationState::CalendarIncomplete,
                $calendar['reason'],
                $input,
                $resolution,
                $startDate,
                $endDate,
                $calendar,
            );
        }

        [$events, $schedule] = $this->contractualEvents($emission, $resolution, $parameter, $endDate);

        try {
            $ratePlan = $this->ratePlan($parameter, $startDate, $endDate);
        } catch (Throwable $exception) {
            // Só se alcança aqui por resolução de calendário fora da janela
            // provada acima ou por prêmio mal configurado. Em nenhum dos casos a
            // tela deve devolver stack trace.
            return $this->failure(
                PuSimulationState::CalculationFailed,
                $this->readableFailure($exception),
                $input,
                $resolution,
                $startDate,
                $endDate,
                $calendar,
            );
        }

        if ($ratePlan['missing_rate_dates'] !== []) {
            return new PuSimulationResult(
                state: PuSimulationState::RatesMissing,
                reason: sprintf(
                    'Faltam %d taxa(s) %s exigida(s) pela configuração simulada.',
                    count($ratePlan['missing_rate_dates']),
                    $parameter->indexer_enum->value === PuIndexer::Cdi->value ? 'CDI' : $parameter->indexer_enum->value,
                ),
                input: $input,
                parameters: $values,
                origins: $origins,
                startDate: $startDate,
                endDate: $endDate,
                requiredRateDates: $ratePlan['required_rate_dates'],
                missingRateDates: $ratePlan['missing_rate_dates'],
                conflictingRates: $ratePlan['conflicting_rates'],
                calendarDiagnostics: $calendar,
                events: $events,
                scheduleDiagnostics: $schedule,
                premium: $this->premiumSummary($parameter),
            );
        }

        try {
            $rows = $this->runOfficialEngine($emission, $parameter, $events, $input->quantity);
        } catch (Throwable $exception) {
            return new PuSimulationResult(
                state: PuSimulationState::CalculationFailed,
                reason: $this->readableFailure($exception),
                input: $input,
                parameters: $values,
                origins: $origins,
                startDate: $startDate,
                endDate: $endDate,
                requiredRateDates: $ratePlan['required_rate_dates'],
                conflictingRates: $ratePlan['conflicting_rates'],
                calendarDiagnostics: $calendar,
                events: $events,
                scheduleDiagnostics: $schedule,
                premium: $this->premiumSummary($parameter),
            );
        }

        $selected = $this->selectRow($rows, $input->focusDate ?? $endDate);

        return new PuSimulationResult(
            state: PuSimulationState::Calculated,
            reason: sprintf('Simulação calculada com %d linha(s) de curva.', count($rows)),
            input: $input,
            parameters: $values,
            origins: $origins,
            startDate: $startDate,
            endDate: $endDate,
            requiredRateDates: $ratePlan['required_rate_dates'],
            conflictingRates: $ratePlan['conflicting_rates'],
            calendarDiagnostics: $calendar,
            events: $events,
            scheduleDiagnostics: $schedule,
            rows: $rows,
            selectedRow: $selected,
            premium: $this->premiumSummary($parameter),
        );
    }

    /**
     * Datas de taxa exigidas pela janela simulada, resolvidas pelo mesmo plano
     * oficial usado na preparação numérica. Read-only por construção.
     *
     * @return array{required_rate_dates:list<string>,missing_rate_dates:list<string>,conflicting_rates:list<array<string,mixed>>}
     */
    public function ratePlan(
        EmissionPuParameter $parameter,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        if (! $parameter->indexer_enum->requiresIndexRates()) {
            return ['required_rate_dates' => [], 'missing_rate_dates' => [], 'conflicting_rates' => []];
        }

        $source = $this->rateSync->publishedSource($parameter->indexer_enum);
        $plan = $this->ratePlans->inspect(
            parameter: $parameter,
            curveStartDate: $startDate,
            homologationEndDate: $endDate,
            source: (string) $source['source'],
            seriesCode: (string) $source['code'],
            sourceReference: sprintf('%s:%s', $source['source'], $source['code']),
        );

        return [
            'required_rate_dates' => $plan['required_rate_dates'],
            'missing_rate_dates' => $plan['missing_rate_dates'],
            'conflicting_rates' => $plan['conflicting_rates'],
        ];
    }

    /**
     * Resolve o parâmetro em memória sem calcular. A tela usa isto para montar o
     * formulário e para descobrir a janela de taxas antes de qualquer sync.
     *
     * @return array{
     *     parameter:?EmissionPuParameter,
     *     values:array<string, mixed>,
     *     origins:array<string, string>,
     *     missing:list<string>,
     *     conflicts:list<array<string, mixed>>,
     *     schedule:array<string, mixed>,
     * }
     */
    public function resolveParameters(Emission $emission, PuSimulationInput $input): array
    {
        return $this->parameters->resolve($emission, $input);
    }

    /**
     * Executa a engine oficial sobre um cenário clonado.
     *
     * O `clone` é obrigatório: a emissão real nunca tem as suas relações
     * substituídas, e o parâmetro simulado nem existe no banco. A engine só lê
     * `puParameter`, `puEvents` e `integralizationHistories` -- as três relações
     * são definidas aqui, então `loadMissing()` interno não consulta nada.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<PuDailyCurveRowData>
     */
    private function runOfficialEngine(
        Emission $emission,
        EmissionPuParameter $parameter,
        array $events,
        ?string $quantity,
    ): array {
        $scenario = clone $emission;
        $scenario->setRelation('puParameter', $parameter);
        $scenario->setRelation('puEvents', $this->eventModels($events));
        $scenario->setRelation('integralizationHistories', $this->integralizationModels($parameter, $quantity));
        $this->indexRateLookup->flushCache();

        return $this->curveGenerator->handle($scenario)->rows;
    }

    /**
     * Eventos contratuais da janela, gerados pelo resolver oficial e
     * materializados apenas como instâncias em memória.
     *
     * @param  array<string, mixed>  $resolution
     * @return array{0:list<array<string, mixed>>, 1:array<string, mixed>}
     */
    private function contractualEvents(
        Emission $emission,
        array $resolution,
        EmissionPuParameter $parameter,
        CarbonImmutable $endDate,
    ): array {
        $persisted = $emission->relationLoaded('puEvents')
            ? $emission->puEvents
            : $emission->puEvents()->get();
        $candidate = $this->eventCandidate($resolution, $parameter);

        if ($candidate === null) {
            // Sem cronograma contratual comprovado, a simulação usa apenas os
            // eventos já persistidos da emissão -- e nunca inventa um.
            $events = $persisted
                ->filter(fn (EmissionPuEvent $event): bool => $event->effective_date !== null
                    && CarbonImmutable::instance($event->effective_date)->lte($endDate))
                ->map(fn (EmissionPuEvent $event): array => [
                    'event_type' => $event->event_type,
                    'original_date' => $event->original_date?->toDateString(),
                    'effective_date' => $event->effective_date?->toDateString(),
                    'amortization_type' => $event->amortization_type,
                    'amortization_value' => $event->amortization_value !== null
                        ? (string) $event->amortization_value
                        : null,
                    'sequence' => $event->sequence,
                ])
                ->values()
                ->all();

            return [$events, [
                'source' => 'persisted_events',
                'resolvable' => false,
                'reason' => 'O cronograma contratual não está comprovado na baseline; a simulação usou apenas os eventos já persistidos da emissão.',
                'event_count' => count($events),
            ]];
        }

        // O resolver oficial lê `puEvents` por acesso de propriedade, o que
        // popularia a relação na instância recebida. A simulação nunca toca o
        // objeto do chamador: a avaliação roda sobre um clone com a relação já
        // definida.
        $isolated = clone $emission;
        $isolated->setRelation('puEvents', $persisted);
        $diagnostics = $this->eventRequirements->evaluate(
            $isolated,
            $candidate,
            $endDate,
            true,
        );

        return [$diagnostics['required_events'], [
            'source' => 'contractual_schedule',
            'resolvable' => (bool) ($diagnostics['resolvable'] ?? false),
            'schedule_supported' => (bool) ($diagnostics['schedule_supported'] ?? false),
            'contractual_schedule_known' => (bool) ($diagnostics['contractual_schedule_known'] ?? false),
            'reason' => ($diagnostics['resolvable'] ?? false)
                ? 'Cronograma contratual resolvido pelo gerador oficial de eventos.'
                : 'O cronograma contratual lido não é suportado ou está incompleto; nenhum evento foi simulado.',
            'event_count' => count($diagnostics['required_events']),
        ]];
    }

    /**
     * Recria o `PuBaselineCandidate` mínimo que o resolver de eventos consome,
     * já com o calendário e o vencimento efetivamente simulados.
     *
     * @param  array<string, mixed>  $resolution
     */
    private function eventCandidate(
        array $resolution,
        EmissionPuParameter $parameter,
    ): ?PuBaselineCandidate {
        $schedule = $resolution['schedule'];

        if (($schedule['first_interest_payment_date'] ?? null) === null) {
            return null;
        }

        return new PuBaselineCandidate(
            configuration: ['calendar_code' => (string) $parameter->calendar_code],
            fields: [],
            contractRequirements: [],
            contractualSchedule: $schedule,
            indexer: $parameter->indexer_enum,
            lookupMode: $parameter->index_rate_lookup_mode_enum,
            calendarFromDate: CarbonImmutable::instance($parameter->curve_start_date),
            curveEndDate: CarbonImmutable::instance($parameter->curve_end_date),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return EloquentCollection<int, EmissionPuEvent>
     */
    private function eventModels(array $events): EloquentCollection
    {
        $models = [];
        $index = 0;

        foreach ($events as $event) {
            $model = new EmissionPuEvent;
            $model->exists = false;
            $model->forceFill([
                'event_type' => $event['event_type'],
                'original_date' => $event['original_date'],
                'effective_date' => $event['effective_date'],
                'amortization_type' => $event['amortization_type'],
                'amortization_value' => $event['amortization_value'] ?? null,
                'sequence' => $event['sequence'] ?? 1,
            ]);
            // A engine ordena por `effective_date|sequence|id`; sem chave real o
            // índice preserva a ordem determinística do cronograma contratual.
            $model->setAttribute('id', ++$index);
            $models[] = $model;
        }

        return new EloquentCollection($models);
    }

    /**
     * Quantidade é opcional e só existe para compor a POSIÇÃO FINANCEIRA
     * (`PU × quantidade`). O PU unitário não depende dela: sem quantidade a
     * engine recebe uma timeline vazia, exatamente como na homologação numérica.
     *
     * @return EloquentCollection<int, IntegralizationHistory>
     */
    private function integralizationModels(
        EmissionPuParameter $parameter,
        ?string $quantity,
    ): EloquentCollection {
        if ($quantity === null || trim($quantity) === '') {
            return new EloquentCollection;
        }

        $model = new IntegralizationHistory;
        $model->exists = false;
        $model->forceFill([
            'date' => CarbonImmutable::instance($parameter->curve_start_date)->toDateString(),
            'quantity' => $quantity,
        ]);
        $model->setAttribute('id', 1);

        return new EloquentCollection([$model]);
    }

    /**
     * Primeira data de exigência financeira, considerando o prêmio pré
     * integralização: é dela que a janela de calendário precisa partir.
     */
    private function calendarStartDate(
        EmissionPuParameter $parameter,
        CarbonImmutable $startDate,
    ): CarbonImmutable {
        try {
            $premiumStart = $this->rateRequirements
                ->firstCouponPreIntegralizationFinancialCalendarStartDate($parameter);
        } catch (Throwable) {
            return $startDate;
        }

        if (! $premiumStart instanceof CarbonImmutable) {
            return $startDate;
        }

        return $premiumStart->lt($startDate) ? $premiumStart : $startDate;
    }

    /**
     * Cobertura do calendário simulado. Distingue explicitamente
     * "matematicamente resolvível" de "pronto para produção": anos não
     * confirmados NÃO impedem a simulação, mas a ausência de decisão explícita
     * de dia útil impede.
     *
     * @return array<string, mixed>
     */
    private function calendarDiagnostics(
        EmissionPuParameter $parameter,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $calendarCode = (string) $parameter->calendar_code;

        try {
            $summary = $this->calendarCoverage->summary($calendarCode, $from, $to);
            $annual = $this->calendarCoverage->annualCoverage($calendarCode, $from, $to);
            // Prova de resolubilidade sobre a janela inteira: a engine chamará
            // `isBusinessDay` em cada data, e um calendário que exige decisão
            // explícita lança exceção quando a linha não existe. Provar as datas
            // sem linha persistida basta -- as que têm linha nunca lançam.
            foreach ($this->calendarCoverage->missingDates($calendarCode, $from, $to) as $missingDate) {
                $this->rateRequirements->resolve(
                    $parameter,
                    CarbonImmutable::parse($missingDate)->startOfDay(),
                );
            }

            $this->rateRequirements->resolve($parameter, $from);
            $this->rateRequirements->resolve($parameter, $to);
        } catch (Throwable $exception) {
            return [
                'resolvable' => false,
                'calendar_code' => $calendarCode,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'reason' => $this->readableFailure($exception),
                'missing_window' => null,
                'annual_coverage' => [],
            ];
        }

        $unconfirmedYears = collect($annual)
            ->reject(fn (array $year): bool => (bool) ($year['confirmed'] ?? false))
            ->pluck('year')
            ->map(fn (mixed $year): int => (int) $year)
            ->values()
            ->all();

        return [
            'resolvable' => true,
            'calendar_code' => $calendarCode,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'reason' => 'O calendário resolve todas as datas da janela simulada.',
            'missing_days' => $summary['missing_count'] ?? 0,
            'first_missing' => $summary['first_missing'] ?? null,
            'holiday_count' => $summary['holiday_count'] ?? 0,
            'unconfirmed_years' => $unconfirmedYears,
            'annual_coverage' => $annual,
        ];
    }

    /**
     * Resumo do prêmio de primeiro cupom exatamente como a engine o calcula.
     * Nenhuma recomposição de fórmula na apresentação.
     *
     * @return array<string, mixed>
     */
    private function premiumSummary(EmissionPuParameter $parameter): array
    {
        if (! $parameter->hasFirstCouponPreIntegralizationPremium()) {
            return ['enabled' => false];
        }

        try {
            $premium = $this->premiumCalculator->calculate($parameter);
            $accrualDates = $this->rateRequirements->firstCouponPreIntegralizationAccrualDates($parameter);
            $rateRequirements = $this->rateRequirements->firstCouponPreIntegralizationRateRequirements($parameter);
        } catch (Throwable $exception) {
            return [
                'enabled' => true,
                'resolvable' => false,
                'reason' => $this->readableFailure($exception),
            ];
        }

        return [
            'enabled' => true,
            'resolvable' => $premium !== null,
            'business_days' => (int) $parameter->first_coupon_pre_integralization_business_days,
            'applies_index_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_index_factor,
            'applies_spread_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_spread_factor,
            'accrual_dates' => array_map(
                fn (CarbonImmutable $date): string => $date->toDateString(),
                $accrualDates,
            ),
            'rate_dates' => array_values(array_filter(array_map(
                fn (PuIndexRateRequirement $requirement): ?string => $requirement
                    ->requiredRateDate()?->toDateString(),
                $rateRequirements,
            ))),
            'memory' => $premium?->toCalculationMemory(),
        ];
    }

    /**
     * @param  list<PuDailyCurveRowData>  $rows
     */
    private function selectRow(array $rows, CarbonImmutable $target): ?PuDailyCurveRowData
    {
        $selected = null;

        foreach ($rows as $row) {
            if ($row->date->lte($target)) {
                $selected = $row;

                continue;
            }

            break;
        }

        return $selected ?? ($rows === [] ? null : $rows[array_key_last($rows)]);
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @param  array<string, mixed>  $calendar
     */
    private function failure(
        PuSimulationState $state,
        string $reason,
        PuSimulationInput $input,
        array $resolution,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
        array $calendar = [],
    ): PuSimulationResult {
        return new PuSimulationResult(
            state: $state,
            reason: $reason,
            input: $input,
            parameters: $resolution['values'],
            origins: $resolution['origins'],
            missingFields: $resolution['missing'],
            startDate: $startDate,
            endDate: $endDate,
            calendarDiagnostics: $calendar,
        );
    }

    /**
     * A tela nunca mostra stack trace: a mensagem da engine já é financeira e
     * descreve a data e a regra que faltaram.
     */
    private function readableFailure(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message === ''
            ? 'A engine de PU não conseguiu concluir o cálculo com os parâmetros informados.'
            : $message;
    }
}
