<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\DTOs\PuBaselineCandidate;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use Carbon\CarbonImmutable;

/**
 * Cronograma contratual de eventos confrontado com os eventos persistidos.
 *
 * Tudo é derivado do candidato: frequência, vencimento, calendário e convenção
 * vêm das evidências, nenhuma data ou quantidade de eventos é fixada aqui.
 *
 * A engine só representa hoje `monthly` + `bullet` + `following_business_day`;
 * qualquer outro padrão resulta em `schedule_supported = false` e bloqueia, em
 * vez de ser aproximado por um cronograma que o contrato não prevê.
 */
final class PuBaselineEventRequirementService
{
    /** @var list<string> */
    private const SUPPORTED_FREQUENCIES = ['monthly'];

    /** @var list<string> */
    private const SUPPORTED_AMORTIZATIONS = ['bullet'];

    /** @var list<string> */
    private const SUPPORTED_PAYMENT_CONVENTIONS = ['following_business_day'];

    public function __construct(
        private readonly BusinessDayCalendar $businessDayCalendar,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(
        Emission $emission,
        PuBaselineCandidate $candidate,
        CarbonImmutable $requestedEndDate,
        bool $calendarTechnicallyReady,
    ): array {
        $schedule = $candidate->contractualSchedule;
        $firstInterestDate = $this->date($schedule['first_interest_payment_date'] ?? null);
        $frequency = $schedule['interest_payment_frequency'] ?? null;
        $amortization = $schedule['amortization'] ?? null;
        $paymentConvention = $schedule['payment_convention'] ?? null;
        $calendarCode = $candidate->configuration['calendar_code'] ?? null;
        $contractualScheduleKnown = $firstInterestDate !== null
            && $candidate->curveEndDate !== null
            && is_string($frequency)
            && is_string($amortization)
            && is_string($paymentConvention);
        $scheduleSupported = in_array($frequency, self::SUPPORTED_FREQUENCIES, true)
            && in_array($amortization, self::SUPPORTED_AMORTIZATIONS, true)
            && in_array($paymentConvention, self::SUPPORTED_PAYMENT_CONVENTIONS, true);
        $base = [
            'resolvable' => false,
            'contractual_schedule_known' => $contractualScheduleKnown,
            'schedule_supported' => $scheduleSupported,
            'schedule' => $schedule,
            'contractual_event_count' => 0,
            'persisted_event_count' => $emission->puEvents->count(),
            'required_events' => [],
            'missing_events' => [],
            'required_event_count' => 0,
            'loaded_required_event_count' => 0,
        ];

        if (! $contractualScheduleKnown
            || ! $scheduleSupported
            || ! $calendarTechnicallyReady
            || ! is_string($calendarCode)
            || $calendarCode === 'PENDING') {
            return $base;
        }

        $allEvents = $this->events(
            $firstInterestDate,
            $candidate->curveEndDate,
            $calendarCode,
        );
        $homologationEndDate = $requestedEndDate->min($candidate->curveEndDate);
        $requiredEvents = collect($allEvents)
            ->filter(fn (array $event): bool => $event['original_date'] <= $homologationEndDate->toDateString())
            ->values()
            ->all();
        $persistedKeys = $emission->puEvents
            ->mapWithKeys(fn (EmissionPuEvent $event): array => [$this->eventKey([
                'event_type' => $event->event_type,
                'original_date' => $event->original_date?->toDateString(),
                'effective_date' => $event->effective_date?->toDateString(),
                'amortization_type' => $event->amortization_type,
                'sequence' => $event->sequence,
            ]) => true]);
        $missingEvents = collect($requiredEvents)
            ->reject(fn (array $event): bool => $persistedKeys->has($this->eventKey($event)))
            ->values()
            ->all();

        return [
            ...$base,
            'resolvable' => true,
            'homologation_end_date' => $homologationEndDate->toDateString(),
            'contractual_event_count' => count($allEvents),
            'required_events' => $requiredEvents,
            'missing_events' => $missingEvents,
            'required_event_count' => count($requiredEvents),
            'loaded_required_event_count' => count($requiredEvents) - count($missingEvents),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function events(
        CarbonImmutable $firstInterestDate,
        CarbonImmutable $maturityDate,
        string $calendarCode,
    ): array {
        $events = [];

        for (
            $interestDate = $firstInterestDate;
            $interestDate->lte($maturityDate);
            $interestDate = $interestDate->addMonthNoOverflow()
        ) {
            $events[] = [
                'event_type' => PuEventType::InterestPayment->value,
                'original_date' => $interestDate->toDateString(),
                'effective_date' => $this->followingBusinessDay($interestDate, $calendarCode)->toDateString(),
                'amortization_type' => PuAmortizationType::None->value,
                'amortization_value' => null,
                'sequence' => 1,
            ];
        }

        $events[] = [
            'event_type' => PuEventType::Amortization->value,
            'original_date' => $maturityDate->toDateString(),
            'effective_date' => $this->followingBusinessDay($maturityDate, $calendarCode)->toDateString(),
            'amortization_type' => PuAmortizationType::Residual->value,
            'amortization_value' => null,
            'sequence' => 1,
        ];

        return $events;
    }

    private function followingBusinessDay(CarbonImmutable $date, string $calendarCode): CarbonImmutable
    {
        $effectiveDate = $date;

        while (! $this->businessDayCalendar->isBusinessDay($effectiveDate, $calendarCode)) {
            $effectiveDate = $effectiveDate->addDay();
        }

        return $effectiveDate;
    }

    /**
     * Identidade de um evento para o confronto contrato × banco.
     *
     * Entram os elementos que o cronograma contratual efetivamente comprova e
     * que tornam dois eventos materialmente distintos: tipo, data original,
     * data efetiva (a convenção aplicada), tipo de amortização e sequência.
     *
     * `amortization_value` fica fora de propósito: para `bullet` o contrato
     * comprova amortização RESIDUAL, cujo valor é apurado pela engine no
     * vencimento e não consta do cronograma. Incluí-lo exigiria que o gate
     * inventasse um valor esperado e rejeitaria eventos legítimos que já o
     * carregam calculado.
     *
     * @param  array<string, mixed>  $event
     */
    private function eventKey(array $event): string
    {
        return implode('|', [
            $event['event_type'] ?? '',
            $event['original_date'] ?? '',
            $event['effective_date'] ?? '',
            $event['amortization_type'] ?? '',
            $event['sequence'] ?? '',
        ]);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->toDateString() === $value ? $date : null;
    }
}
