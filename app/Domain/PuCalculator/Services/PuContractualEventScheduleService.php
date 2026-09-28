<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cronograma contratual de eventos de PU até o vencimento.
 *
 * O cronograma vem do mesmo cálculo que o gate usa
 * (`PuBaselineEventRequirementService`) e o confronto com os eventos gravados,
 * do mesmo planner da preparação numérica (`PuEventPlanService`): não existe um
 * segundo jeito de derivar datas de pagamento.
 *
 * Criar um evento DEPOIS do último dia calculado não altera nenhuma curva
 * persistida, e por isso a criação se limita a esses eventos. Um evento que
 * falta dentro do período já calculado é reportado e nunca criado aqui: a curva
 * gravada o ignorou e precisa ser refeita por decisão explícita. Eventos
 * gravados que divergem do contrato também só são reportados -- nada é
 * sobrescrito.
 */
final class PuContractualEventScheduleService
{
    public const ACTION_CREATED = 'contractual_events_created';

    public const ACTION_NOTHING_TO_CREATE = 'contractual_events_nothing_to_create';

    public const ACTION_UNAVAILABLE = 'contractual_schedule_unavailable';

    public const ACTION_CONCURRENT_CHANGE = 'contractual_events_concurrent_change';

    /**
     * A data efetiva segue o dia útil seguinte e pode passar do vencimento; a
     * cobertura do calendário é exigida com essa folga.
     */
    private const CONVENTION_MARGIN_DAYS = 10;

    public function __construct(
        private readonly PuBaselineCandidateFactory $candidates,
        private readonly PuBaselineEventRequirementService $eventRequirements,
        private readonly PuEventPlanService $eventPlans,
        private readonly BusinessCalendarCoverageService $calendarCoverage,
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * @return array{
     *     available: bool,
     *     reason: ?string,
     *     maturity_date: ?string,
     *     last_calculated_date: ?string,
     *     contractual_events: list<array<string, mixed>>,
     *     present_events: list<array<string, mixed>>,
     *     missing_events: list<array<string, mixed>>,
     *     creatable_events: list<array<string, mixed>>,
     *     missing_in_calculated_period: list<array<string, mixed>>,
     *     conflicting_events: list<array<string, mixed>>,
     * }
     */
    public function plan(Emission $emission): array
    {
        if (! $this->candidates->supports($emission)) {
            return $this->unavailable('A emissão não tem cronograma contratual confirmado em Instrumentos Jurídicos.');
        }

        $candidate = $this->candidates->make($emission, null);
        $maturityDate = $candidate->curveEndDate;
        $firstInterestDate = $candidate->contractualSchedule['first_interest_payment_date'] ?? null;
        $calendarCode = $candidate->configuration['calendar_code'] ?? null;

        if ($maturityDate === null
            || ! is_string($firstInterestDate)
            || ! is_string($calendarCode)
            || $calendarCode === 'PENDING') {
            return $this->unavailable('O cronograma contratual está incompleto: confirme em Instrumentos Jurídicos o primeiro pagamento, o vencimento e o calendário.');
        }

        $calendarCovered = $this->calendarCoverage->uncoveredDates(
            $calendarCode,
            CarbonImmutable::parse($firstInterestDate),
            $maturityDate->addDays(self::CONVENTION_MARGIN_DAYS),
        ) === [];
        $emission->load('puEvents');
        $schedule = $this->eventRequirements->evaluate($emission, $candidate, $maturityDate, $calendarCovered);

        if (! ($schedule['resolvable'] ?? false)) {
            return $this->unavailable(match (true) {
                ! ($schedule['contractual_schedule_known'] ?? false) => 'O cronograma contratual está incompleto: confirme em Instrumentos Jurídicos a periodicidade, a amortização e a convenção de pagamento.',
                ! ($schedule['schedule_supported'] ?? false) => 'O cronograma contratual usa uma periodicidade, amortização ou convenção que a engine de eventos ainda não representa.',
                default => sprintf('O calendário %s não tem cobertura oficial até o vencimento; as datas efetivas não podem ser decididas.', $calendarCode),
            });
        }

        $inspection = $this->eventPlans->inspect($emission, $schedule['required_events'], $maturityDate);
        $lastCalculated = EmissionPuDailyCurve::query()->where('emission_id', $emission->id)->max('curve_date');
        $lastCalculatedDate = $lastCalculated !== null
            ? CarbonImmutable::parse((string) $lastCalculated)->toDateString()
            : null;
        [$missingInCalculatedPeriod, $creatableEvents] = collect($inspection['missing_events'])
            ->partition(fn (array $event): bool => $lastCalculatedDate !== null
                && (string) $event['effective_date'] <= $lastCalculatedDate);

        return [
            'available' => true,
            'reason' => null,
            'maturity_date' => $maturityDate->toDateString(),
            'last_calculated_date' => $lastCalculatedDate,
            'contractual_events' => $schedule['required_events'],
            'present_events' => $inspection['present_events'],
            'missing_events' => $inspection['missing_events'],
            'creatable_events' => $creatableEvents->values()->all(),
            'missing_in_calculated_period' => $missingInCalculatedPeriod->values()->all(),
            'conflicting_events' => $inspection['conflicting_events'],
        ];
    }

    /**
     * Cria os eventos contratuais que faltam depois do último dia calculado.
     *
     * @return array{action: string, created: int, first_date: ?string, last_date: ?string, plan: array<string, mixed>}
     *
     * @throws AuthorizationException
     */
    public function write(Emission $emission, User $actor): array
    {
        if (! $actor->can(AccessPermission::PuParametersConfigure->value)) {
            throw new AuthorizationException('Você não possui permissão para cadastrar eventos de PU.');
        }

        try {
            return DB::transaction(function () use ($emission, $actor): array {
                $lockedEmission = Emission::query()->whereKey($emission->id)->lockForUpdate()->firstOrFail();
                $plan = $this->plan($lockedEmission);

                if (! $plan['available']) {
                    return $this->result(self::ACTION_UNAVAILABLE, [], $plan);
                }

                if ($plan['creatable_events'] === []) {
                    return $this->result(self::ACTION_NOTHING_TO_CREATE, [], $plan);
                }

                $created = collect($plan['creatable_events'])
                    ->map(fn (array $event): EmissionPuEvent => EmissionPuEvent::query()->create([
                        'emission_id' => $lockedEmission->id,
                        'event_type' => $event['event_type'],
                        'original_date' => $event['original_date'],
                        'effective_date' => $event['effective_date'],
                        'amortization_type' => $event['amortization_type'],
                        'amortization_value' => $event['amortization_value'],
                        'sequence' => $event['sequence'],
                        'description' => 'Gerado do cronograma contratual confirmado em Instrumentos Jurídicos.',
                    ]))
                    ->all();

                $this->auditLog->logContractualScheduleGenerated(
                    emission: $lockedEmission,
                    actor: $actor,
                    insertedEventIds: array_map(fn (EmissionPuEvent $event): int => $event->id, $created),
                    missingInCalculatedPeriod: $plan['missing_in_calculated_period'],
                    conflicts: $plan['conflicting_events'],
                    lastCalculatedDate: $plan['last_calculated_date'],
                );

                return $this->result(self::ACTION_CREATED, $plan['creatable_events'], $plan);
            });
        } catch (UniqueConstraintViolationException) {
            // unique(emission_id, event_type, effective_date, sequence): outra
            // gravação ocupou a data no meio do caminho. Nada foi criado.
            return $this->result(self::ACTION_CONCURRENT_CHANGE, [], $this->plan($emission->fresh()));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $createdEvents
     * @param  array<string, mixed>  $plan
     * @return array{action: string, created: int, first_date: ?string, last_date: ?string, plan: array<string, mixed>}
     */
    private function result(string $action, array $createdEvents, array $plan): array
    {
        return [
            'action' => $action,
            'created' => count($createdEvents),
            'first_date' => $createdEvents[0]['effective_date'] ?? null,
            'last_date' => $createdEvents === [] ? null : $createdEvents[array_key_last($createdEvents)]['effective_date'],
            'plan' => $plan,
        ];
    }

    /**
     * @return array{
     *     available: false,
     *     reason: string,
     *     maturity_date: null,
     *     last_calculated_date: null,
     *     contractual_events: list<array<string, mixed>>,
     *     present_events: list<array<string, mixed>>,
     *     missing_events: list<array<string, mixed>>,
     *     creatable_events: list<array<string, mixed>>,
     *     missing_in_calculated_period: list<array<string, mixed>>,
     *     conflicting_events: list<array<string, mixed>>,
     * }
     */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'maturity_date' => null,
            'last_calculated_date' => null,
            'contractual_events' => [],
            'present_events' => [],
            'missing_events' => [],
            'creatable_events' => [],
            'missing_in_calculated_period' => [],
            'conflicting_events' => [],
        ];
    }
}
