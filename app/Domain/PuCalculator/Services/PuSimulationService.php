<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuBaselineCandidate;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\DTOs\PuSimulationResult;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
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
        // Perfil de cálculo da simulação. Omissão é sempre contratual.
        $profile = $input->calculationProfile();

        if (! $profile->isContractual() && $parameter->indexer_enum !== PuIndexer::Cdi) {
            return $this->failure(
                PuSimulationState::MissingInput,
                'A compatibilidade com o sistema legado foi comprovada apenas para a engine CDI + spread. Selecione o perfil contratual.',
                $input,
                $resolution,
                $startDate,
                $endDate,
            );
        }

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
        $calendar = $this->calendarDiagnostics(
            $parameter,
            $calendarStart,
            $endDate,
            $input->accrualCalendarCode(),
        );

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

        try {
            [$events, $schedule] = $this->contractualEvents($emission, $resolution, $parameter, $endDate);
        } catch (Throwable $exception) {
            // O cronograma contratual é percorrido até o VENCIMENTO, que fica
            // fora da janela cujo calendário foi provado acima. Um calendário
            // que exige decisão explícita e não cobre os anos até o vencimento
            // é reportado como falha de cálculo -- nunca como cronograma vazio,
            // que silenciaria cupons devidos dentro da janela.
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

        // Hipótese de calendário de OBSERVAÇÃO do índice. Nula por padrão: a
        // simulação continua resolvendo as datas de taxa pelo calendário
        // contratual, exatamente como a produção.
        $indexRateCalendarCode = $input->indexRateCalendarCode();
        // Hipótese de calendário de ACCRUAL da curva. Nula por padrão: a
        // simulação continua contando Dia Útil pelo calendário contratual.
        // Os eventos já foram datados acima, pela convenção de pagamento sobre
        // o calendário contratual, e não são reprocessados por esta hipótese.
        $accrualCalendarCode = $input->accrualCalendarCode();

        try {
            $ratePlan = $this->ratePlan(
                $parameter,
                $startDate,
                $endDate,
                $indexRateCalendarCode,
                $accrualCalendarCode,
            );
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
                premium: $this->premiumSummary($parameter, $indexRateCalendarCode),
            );
        }

        try {
            $rows = $this->runOfficialEngine(
                $emission,
                $parameter,
                $events,
                $indexRateCalendarCode,
                $accrualCalendarCode,
                $profile,
            );
            // Reconciliação: o perfil legado sempre carrega a curva contratual ao lado, para que a
            // divergência seja exibida em vez de substituir silenciosamente a referência oficial.
            $profileComparison = $profile->isContractual() ? [] : $this->profileComparison(
                $rows,
                $this->runOfficialEngine(
                    $emission,
                    $parameter,
                    $events,
                    $indexRateCalendarCode,
                    $accrualCalendarCode,
                    PuCalculationProfile::Contractual,
                ),
            );
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
                premium: $this->premiumSummary($parameter, $indexRateCalendarCode),
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
            premium: $this->premiumSummary($parameter, $indexRateCalendarCode),
            profileComparison: $profileComparison,
        );
    }

    /**
     * Comparação linha a linha entre o perfil escolhido e o contratual.
     *
     * Existe para NÃO esconder diferença: o que a tela mostra é a divergência medida, nunca um
     * ajuste. Todas as contas são `bcsub` sobre as strings que as duas execuções da engine
     * produziram -- nenhuma recomposição de fórmula acontece aqui.
     *
     * @param  list<PuDailyCurveRowData>  $rows
     * @param  list<PuDailyCurveRowData>  $contractualRows
     * @return array<string, array<string, string|null>>
     */
    private function profileComparison(array $rows, array $contractualRows): array
    {
        $contractualByDate = [];

        foreach ($contractualRows as $contractualRow) {
            $contractualByDate[$contractualRow->date->toDateString()] = $contractualRow;
        }

        $comparison = [];

        foreach ($rows as $row) {
            $date = $row->date->toDateString();
            $reference = $contractualByDate[$date] ?? null;

            if (! $reference instanceof PuDailyCurveRowData) {
                continue;
            }

            $comparison[$date] = [
                'interest_contractual' => $reference->interestRealUnitValue,
                'interest_profile' => $row->interestRealUnitValue,
                'interest_delta' => $this->delta($row->interestRealUnitValue, $reference->interestRealUnitValue),
                'payment_contractual' => $reference->paymentTotalUnitValue,
                'payment_profile' => $row->paymentTotalUnitValue,
                'payment_delta' => $this->delta($row->paymentTotalUnitValue, $reference->paymentTotalUnitValue),
                'updated_unit_value_contractual' => $reference->updatedUnitValue,
                'updated_unit_value_profile' => $row->updatedUnitValue,
                'updated_unit_value_delta' => $this->delta($row->updatedUnitValue, $reference->updatedUnitValue),
                'residual_unit_value_contractual' => $reference->residualUnitValue,
                'residual_unit_value_profile' => $row->residualUnitValue,
                'residual_unit_value_delta' => $this->delta($row->residualUnitValue, $reference->residualUnitValue),
                'first_coupon_premium_contractual' => ($reference->calculationMemory['first_coupon_pre_integralization_premium_applied'] ?? false) ? 'sim' : 'não',
                'first_coupon_premium_profile' => ($row->calculationMemory['first_coupon_pre_integralization_premium_applied'] ?? false) ? 'sim' : 'não',
            ];
        }

        return $comparison;
    }

    /** Diferença perfil - contratual, na escala unitária, sem passar por float. */
    private function delta(string $profileValue, string $contractualValue): string
    {
        return bcsub($profileValue, $contractualValue, DecimalRounder::UNIT_SCALE);
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
        ?string $indexRateCalendarCode = null,
        ?string $accrualCalendarCode = null,
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
            indexRateCalendarCode: $indexRateCalendarCode,
            accrualCalendarCode: $accrualCalendarCode,
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
     * A timeline fica vazia como na homologação unitária: a primeira
     * integralização vem de `curve_start_date`, e a quantidade de posição
     * pertence exclusivamente ao resultado da simulação.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<PuDailyCurveRowData>
     */
    private function runOfficialEngine(
        Emission $emission,
        EmissionPuParameter $parameter,
        array $events,
        ?string $indexRateCalendarCode = null,
        ?string $accrualCalendarCode = null,
        PuCalculationProfile $profile = PuCalculationProfile::Contractual,
    ): array {
        $scenario = clone $emission;
        $scenario->setRelation('puParameter', $parameter);
        $scenario->setRelation('puEvents', $this->eventModels($events));
        $scenario->setRelation('integralizationHistories', new EloquentCollection);
        $this->indexRateLookup->flushCache();

        return $this->curveGenerator->handle(
            $scenario,
            $indexRateCalendarCode,
            $accrualCalendarCode,
            $profile,
        )->rows;
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
     * já com o calendário simulado e o VENCIMENTO CONTRATUAL do instrumento.
     *
     * O `curveEndDate` do candidato é vencimento, não recorte de tela: é dele
     * que o resolver deriva o último cupom e a amortização residual. Entregar
     * aqui o fim da janela simulada fazia o cronograma contratual terminar na
     * data escolhida pelo usuário, e a curva resgatava o principal num dia em
     * que o contrato não prevê resgate nenhum.
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
            curveEndDate: $this->contractualCurveEndDate($resolution, $parameter),
        );
    }

    /**
     * Vencimento contratual resolvido pela simulação, ANTES do recorte de
     * janela.
     *
     * `PuSimulationParameterFactory` preserva esse valor em
     * `contractual_curve_end_date` justamente porque `curve_end_date` do
     * parâmetro em memória já vem truncado em `simulationEndDate` -- é o limite
     * da curva entregue à engine, e nunca o fim do instrumento. Sem valor
     * contratual resolvido (emissão sem baseline e sem parâmetro persistido), o
     * único vencimento conhecido é o próprio limite configurado, e é ele que
     * segue valendo.
     *
     * @param  array<string, mixed>  $resolution
     */
    private function contractualCurveEndDate(
        array $resolution,
        EmissionPuParameter $parameter,
    ): CarbonImmutable {
        $contractual = $resolution['values']['contractual_curve_end_date'] ?? null;

        return is_string($contractual) && $contractual !== ''
            ? CarbonImmutable::parse($contractual)->startOfDay()
            : CarbonImmutable::instance($parameter->curve_end_date)->startOfDay();
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
        ?string $accrualCalendarCode = null,
    ): array {
        $calendarCode = (string) $parameter->calendar_code;
        $accrualOverride = $accrualCalendarCode !== null ? trim($accrualCalendarCode) : '';
        $accrualCalendar = $accrualOverride !== '' ? $accrualOverride : $calendarCode;

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

            // Sob hipótese de accrual, é o calendário de accrual que a engine consulta em cada data.
            // Provar só o contratual deixaria o usuário escolher um calendário sem cobertura e receber
            // a exceção crua do meio do cálculo em vez deste diagnóstico.
            if ($accrualCalendar !== $calendarCode) {
                $accrualSummary = $this->calendarCoverage->summary($accrualCalendar, $from, $to);
                $accrualAnnual = $this->calendarCoverage->annualCoverage($accrualCalendar, $from, $to);

                foreach ($this->calendarCoverage->missingDates($accrualCalendar, $from, $to) as $missingDate) {
                    $this->rateRequirements->resolve(
                        $parameter,
                        CarbonImmutable::parse($missingDate)->startOfDay(),
                        null,
                        $accrualCalendar,
                    );
                }

                $this->rateRequirements->resolve($parameter, $from, null, $accrualCalendar);
                $this->rateRequirements->resolve($parameter, $to, null, $accrualCalendar);
            }
        } catch (Throwable $exception) {
            return [
                'resolvable' => false,
                'calendar_code' => $calendarCode,
                'accrual_calendar_code' => $accrualCalendar,
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
            'accrual_calendar_code' => $accrualCalendar,
            'accrual_calendar_overridden' => $accrualCalendar !== $calendarCode,
            'accrual_annual_coverage' => $accrualAnnual ?? [],
            'accrual_missing_days' => $accrualSummary['missing_count'] ?? 0,
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
    private function premiumSummary(
        EmissionPuParameter $parameter,
        ?string $indexRateCalendarCode = null,
    ): array {
        if (! $parameter->hasFirstCouponPreIntegralizationPremium()) {
            return ['enabled' => false];
        }

        try {
            $premium = $this->premiumCalculator->calculate($parameter, $indexRateCalendarCode);
            $accrualDates = $this->rateRequirements->firstCouponPreIntegralizationAccrualDates($parameter);
            $rateRequirements = $this->rateRequirements->firstCouponPreIntegralizationRateRequirements(
                $parameter,
                $indexRateCalendarCode,
            );
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
