<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\DTOs\PuObligationComponentData;
use App\Domain\PuCalculator\DTOs\PuObligationDraft;
use App\Domain\PuCalculator\DTOs\PuObligationSchedule;
use App\Domain\PuCalculator\DTOs\PuOfficialCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationComponentOwner;
use App\Domain\PuCalculator\Enums\PuObligationComponentStatus;
use App\Domain\PuCalculator\Enums\PuObligationLifecycle;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuObligation;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Lê o cronograma de obrigações que a curva oficial vigente descreve (Fase 5).
 *
 * As obrigações nascem do cronograma contratual APROVADO na versão oficial (o
 * retrato de insumos), não das tabelas vivas: o que a oficial não aprovou não é
 * obrigação governada. Cada pagamento do cronograma (juros e amortização
 * ordinários de uma data efetiva), cada amortização extraordinária e o vencimento
 * antecipado viram uma obrigação com identidade estável -- a data CONTRATUAL (a
 * original, quando a convenção de pagamento deslocou a efetiva), não a efetiva,
 * para que um ajuste de calendário não crie outra obrigação.
 *
 * O valor esperado só vem das linhas REALIZADAS da oficial. Data que a curva
 * ainda não alcançou fica aguardando índice (nada é projetado); trecho que o
 * reprocessamento pôs em dúvida fica aguardando nova versão. Cada componente sai
 * separado e em 2 casas: juros ordinários e amortização ordinária como a curva os
 * grava, e as parcelas extraordinária e acelerada pela memória de cálculo da
 * própria linha. O cronograma informado (planilha ou cadastro) só contribui com o
 * que a curva não calcula -- o prêmio --, e um valor informado que contradiz o
 * cálculo torna a obrigação incompleta em vez de entrar na soma.
 *
 * Depois de um vencimento antecipado, os pagamentos ordinários posteriores
 * continuam descritos, mas superados por ele.
 */
final class PuObligationScheduleBuilder
{
    public function __construct(
        private readonly PuCurveInputSnapshotService $snapshots,
        private readonly PuOfficialCurveFreshnessService $freshness,
        private readonly PuCurveHorizonResolver $horizons,
        private readonly DecimalRounder $rounder,
    ) {}

    /**
     * @param  Collection<int, Payment>  $payments  cronograma informado da emissão
     */
    public function build(Emission $emission, ?EmissionPuCurveVersion $official, Collection $payments): PuObligationSchedule
    {
        if (! $official instanceof EmissionPuCurveVersion) {
            return new PuObligationSchedule(null, null);
        }

        $rows = EmissionPuDailyCurve::query()
            ->where('curve_version_id', $official->id)
            ->whereNotNull('event_effective_date')
            ->orderBy('curve_date')
            ->get()
            ->keyBy(fn (EmissionPuDailyCurve $row): string => $this->date($row->curve_date));
        $lastDate = EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->max('curve_date');
        $realizedThrough = $lastDate !== null ? $this->date($lastDate) : null;
        $status = $this->freshness->status($emission);
        $status = $status->versionId === $official->id ? $status : null;
        $snapshot = $this->snapshots->forVersion($official);

        $entries = $snapshot instanceof PuCurveInputSnapshot
            ? $this->fromSnapshot($snapshot, $rows, $realizedThrough, $status)
            : $this->fromRows($rows, $status);

        [$entries, $unmatched] = $this->attachInformedSchedule($entries, $payments, $rows, $realizedThrough);

        return new PuObligationSchedule(
            $official->id,
            $official->calculation_version,
            array_map(fn (array $entry): PuObligationDraft => $this->draft($entry), $entries),
            $unmatched,
        );
    }

    /**
     * @param  Collection<string, EmissionPuDailyCurve>  $rows
     * @return list<array<string, mixed>>
     */
    private function fromSnapshot(PuCurveInputSnapshot $snapshot, Collection $rows, ?string $realizedThrough, ?PuOfficialCurveStatus $status): array
    {
        $events = $snapshot->events();
        $ids = is_array($snapshot->provenance['event_ids'] ?? null) ? $snapshot->provenance['event_ids'] : [];
        $idOf = fn (array $event): ?int => isset($ids[PuCurveInputSnapshot::eventIdentity($event)])
            ? (int) $ids[PuCurveInputSnapshot::eventIdentity($event)]
            : null;
        $earlyMaturityDate = $this->horizons->earlyMaturityDate($events);
        $earlyMaturityKey = null;
        $entries = [];

        foreach ($events as $event) {
            if (PuEventType::tryFrom((string) $event['event_type']) !== PuEventType::EarlyMaturity) {
                continue;
            }

            $effective = (string) $event['effective_date'];
            $sequence = (int) $event['sequence'];
            $entry = $this->entry(PuObligationType::EarlyMaturity, $effective, $sequence, $effective, array_filter([$idOf($event)]));
            $entries[] = $this->calculate($entry, $rows->get($effective), $realizedThrough, $status, fn (EmissionPuDailyCurve $row): array => $this->earlyMaturityComponents($row));
            $earlyMaturityKey ??= EmissionPuObligation::identityKey(PuObligationType::EarlyMaturity, $effective, $sequence);
        }

        $groups = [];

        foreach ($events as $event) {
            if (PuEventType::tryFrom((string) $event['event_type'])?->isScheduledPayment() ?? false) {
                $groups[(string) $event['effective_date']][] = $event;
            }
        }

        ksort($groups);
        $sequences = [];

        foreach ($groups as $effective => $group) {
            $effective = (string) $effective;
            $contractual = min(array_map(fn (array $event): string => (string) ($event['original_date'] ?? $event['effective_date']), $group));
            $sequence = $sequences[$contractual] = ($sequences[$contractual] ?? 0) + 1;
            $types = array_map(fn (array $event): ?PuEventType => PuEventType::tryFrom((string) $event['event_type']), $group);
            $hasInterest = in_array(PuEventType::InterestPayment, $types, true);
            $hasAmortization = in_array(PuEventType::Amortization, $types, true);
            $entry = $this->entry(PuObligationType::ScheduledPayment, $contractual, $sequence, $effective, array_values(array_filter(array_map($idOf, $group))));

            if ($earlyMaturityDate !== null && $effective > $earlyMaturityDate) {
                $entries[] = $this->supersededByEarlyMaturity($entry, $earlyMaturityKey);

                continue;
            }

            $entries[] = $this->calculate(
                $entry,
                $rows->get($effective),
                $realizedThrough,
                $status,
                fn (EmissionPuDailyCurve $row): array => $this->scheduledComponents($row, $hasInterest, $hasAmortization),
            );
        }

        foreach ($events as $event) {
            if (PuEventType::tryFrom((string) $event['event_type']) !== PuEventType::ExtraordinaryAmortization) {
                continue;
            }

            $effective = (string) $event['effective_date'];
            $sequence = (int) $event['sequence'];
            $entry = $this->entry(PuObligationType::ExtraordinaryAmortization, $effective, $sequence, $effective, array_filter([$idOf($event)]));

            if ($earlyMaturityDate !== null && $effective > $earlyMaturityDate) {
                $entries[] = $this->supersededByEarlyMaturity($entry, $earlyMaturityKey);

                continue;
            }

            $entries[] = $this->calculate(
                $entry,
                $rows->get($effective),
                $realizedThrough,
                $status,
                fn (EmissionPuDailyCurve $row): array => $this->extraordinaryAmortizationComponents($row, $sequence),
            );
        }

        return $entries;
    }

    /**
     * Versão sem retrato de insumos (anterior à Fase 4, fábrica de teste ou
     * candidata promovida): só os pagamentos já realizados que as linhas mostram,
     * sem cronograma futuro.
     *
     * @param  Collection<string, EmissionPuDailyCurve>  $rows
     * @return list<array<string, mixed>>
     */
    private function fromRows(Collection $rows, ?PuOfficialCurveStatus $status): array
    {
        $entries = [];
        $sequences = [];

        foreach ($rows as $date => $row) {
            if (bccomp((string) $row->payment_total_unit_value, '0', 16) !== 1) {
                continue;
            }

            $effective = (string) $date;
            $contractual = $row->event_original_date !== null ? $this->date($row->event_original_date) : $effective;
            $sequence = $sequences[$contractual] = ($sequences[$contractual] ?? 0) + 1;
            $memory = is_array($row->calculation_memory) ? $row->calculation_memory : [];
            $entry = $this->entry(PuObligationType::ScheduledPayment, $contractual, $sequence, $effective, []);

            if (isset($memory['extraordinary_amortizations']) || isset($memory['early_maturity'])) {
                $entries[] = [
                    ...$entry,
                    'state' => PuObligationCalculationState::Unsupported,
                    'state_reason' => 'A versão oficial não tem retrato de insumos para separar amortização extraordinária ou vencimento antecipado desta data.',
                ];

                continue;
            }

            $entries[] = $this->calculate($entry, $row, $effective, $status, fn (EmissionPuDailyCurve $calculated): array => $this->scheduledComponents(
                $calculated,
                bccomp((string) $calculated->interest_payment_unit_value, '0', 16) === 1,
                bccomp((string) $calculated->amortization_unit_value, '0', 16) === 1,
            ));
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  callable(EmissionPuDailyCurve): list<PuObligationComponentData>  $components
     * @return array<string, mixed>
     */
    private function calculate(array $entry, ?EmissionPuDailyCurve $row, ?string $realizedThrough, ?PuOfficialCurveStatus $status, callable $components): array
    {
        $due = (string) $entry['due_date'];

        if (! $row instanceof EmissionPuDailyCurve) {
            if ($realizedThrough === null || $due > $realizedThrough) {
                $pendingFrom = $status?->contractualChangeFrom?->toDateString();

                return $pendingFrom !== null && $pendingFrom <= $due
                    ? [...$entry, 'state' => PuObligationCalculationState::AwaitingNewVersion, 'state_reason' => sprintf(
                        'Há mudança contratual aprovada só a partir de %s: só uma nova versão homologada calcula esta data.',
                        CarbonImmutable::parse($pendingFrom)->format('d/m/Y'),
                    )]
                    : [...$entry, 'state' => PuObligationCalculationState::AwaitingIndex, 'state_reason' => sprintf(
                        'A curva oficial realizada ainda não chegou a %s: o valor depende de índice ainda não divulgado e não é projetado.',
                        CarbonImmutable::parse($due)->format('d/m/Y'),
                    )];
            }

            return [...$entry, 'state' => PuObligationCalculationState::Unsupported, 'state_reason' => sprintf(
                'A curva oficial cobre %s, mas não tem a linha de pagamento dessa data: o valor esperado não é confiável.',
                CarbonImmutable::parse($due)->format('d/m/Y'),
            )];
        }

        if ($status instanceof PuOfficialCurveStatus && ! $status->isReliableAt(CarbonImmutable::parse($due))) {
            return [...$entry, 'state' => PuObligationCalculationState::ReprocessingRequired, 'state_reason' => $status->reason
                ?? 'O trecho da curva oficial que calculou esta data precisa de reprocessamento.'];
        }

        return [...$entry, 'engine' => $components($row), 'row' => $row, 'state' => PuObligationCalculationState::Calculated];
    }

    /**
     * @return list<PuObligationComponentData>
     */
    private function scheduledComponents(EmissionPuDailyCurve $row, bool $hasInterest, bool $hasAmortization): array
    {
        $components = [];
        $quantity = (string) $row->quantity;
        $source = ['curve_version_id' => $row->curve_version_id, 'curve_date' => $this->date($row->curve_date)];

        if ($hasInterest) {
            $components[] = new PuObligationComponentData(
                component: PuObligationComponent::OrdinaryInterest,
                owner: PuObligationComponentOwner::OfficialCurve,
                status: PuObligationComponentStatus::Calculated,
                amount: $this->money((string) $row->interest_payment_value),
                unitAmount: (string) $row->interest_payment_unit_value,
                quantity: $quantity,
                source: [...$source, 'field' => 'interest_payment_value'],
            );
        }

        if ($hasAmortization) {
            [$unit, $amount] = $this->ordinaryAmortization($row);
            $components[] = new PuObligationComponentData(
                component: PuObligationComponent::OrdinaryAmortization,
                owner: PuObligationComponentOwner::OfficialCurve,
                status: PuObligationComponentStatus::Calculated,
                amount: $amount,
                unitAmount: $unit,
                quantity: $quantity,
                source: [...$source, 'field' => 'amortization_value'],
            );
        }

        return $components;
    }

    /**
     * @return list<PuObligationComponentData>
     */
    private function extraordinaryAmortizationComponents(EmissionPuDailyCurve $row, int $sequence): array
    {
        $memory = is_array($row->calculation_memory) ? $row->calculation_memory : [];
        $source = ['curve_version_id' => $row->curve_version_id, 'curve_date' => $this->date($row->curve_date), 'sequence' => $sequence];
        $entry = collect($memory['extraordinary_amortizations'] ?? [])->first(fn ($item): bool => is_array($item) && (int) ($item['sequence'] ?? 0) === $sequence);

        if (! is_array($entry) || ! is_numeric($entry['unit_value_raw'] ?? null)) {
            return [$this->unsupported(
                PuObligationComponent::ExtraordinaryAmortization,
                PuObligationComponentOwner::OfficialCurve,
                'A linha da curva oficial não separa esta amortização extraordinária.',
                $source,
            )];
        }

        return [new PuObligationComponentData(
            component: PuObligationComponent::ExtraordinaryAmortization,
            owner: PuObligationComponentOwner::OfficialCurve,
            status: PuObligationComponentStatus::Calculated,
            amount: $this->moneyFromUnit((string) $entry['unit_value_raw'], (string) $row->quantity),
            unitAmount: $this->rounder->round((string) $entry['unit_value_raw'], DecimalRounder::UNIT_SCALE),
            quantity: (string) $row->quantity,
            source: [...$source, 'field' => 'calculation_memory.extraordinary_amortizations'],
        )];
    }

    /**
     * @return list<PuObligationComponentData>
     */
    private function earlyMaturityComponents(EmissionPuDailyCurve $row): array
    {
        $memory = is_array($row->calculation_memory) ? $row->calculation_memory : [];
        $earlyMaturity = is_array($memory['early_maturity'] ?? null) ? $memory['early_maturity'] : null;
        $source = ['curve_version_id' => $row->curve_version_id, 'curve_date' => $this->date($row->curve_date)];

        if ($earlyMaturity === null || ! is_numeric($earlyMaturity['accelerated_principal_unit_value_raw'] ?? null)) {
            return [$this->unsupported(
                PuObligationComponent::AcceleratedPrincipal,
                PuObligationComponentOwner::OfficialCurve,
                'A linha da curva oficial não separa o principal acelerado do vencimento antecipado.',
                $source,
            )];
        }

        $components = [new PuObligationComponentData(
            component: PuObligationComponent::AcceleratedPrincipal,
            owner: PuObligationComponentOwner::OfficialCurve,
            status: PuObligationComponentStatus::Calculated,
            amount: $this->moneyFromUnit((string) $earlyMaturity['accelerated_principal_unit_value_raw'], (string) $row->quantity),
            unitAmount: $this->rounder->round((string) $earlyMaturity['accelerated_principal_unit_value_raw'], DecimalRounder::UNIT_SCALE),
            quantity: (string) $row->quantity,
            source: [...$source, 'field' => 'calculation_memory.early_maturity'],
        )];

        if (($earlyMaturity['interest_paid_by'] ?? null) === 'early_maturity') {
            $components[] = new PuObligationComponentData(
                component: PuObligationComponent::OrdinaryInterest,
                owner: PuObligationComponentOwner::OfficialCurve,
                status: PuObligationComponentStatus::Calculated,
                amount: $this->money((string) $row->interest_payment_value),
                unitAmount: (string) $row->interest_payment_unit_value,
                quantity: (string) $row->quantity,
                source: [...$source, 'field' => 'interest_payment_value'],
            );
        }

        return $components;
    }

    /**
     * Amortização ORDINÁRIA da linha: a total menos as parcelas extraordinárias e a
     * acelerada. Linha sem elas é exatamente o valor gravado.
     *
     * @return array{0: string, 1: string}
     */
    private function ordinaryAmortization(EmissionPuDailyCurve $row): array
    {
        $memory = is_array($row->calculation_memory) ? $row->calculation_memory : [];
        $extraordinary = is_array($memory['extraordinary_amortizations'] ?? null) ? $memory['extraordinary_amortizations'] : [];
        $accelerated = $memory['early_maturity']['accelerated_principal_unit_value_raw'] ?? null;

        if ($extraordinary === [] && $accelerated === null) {
            return [(string) $row->amortization_unit_value, $this->money((string) $row->amortization_value)];
        }

        $unit = (string) ($memory['amortization_unit_value_raw'] ?? $row->amortization_unit_value);

        foreach ($extraordinary as $item) {
            $unit = bcsub($unit, (string) ($item['unit_value_raw'] ?? '0'), DecimalRounder::CALCULATION_SCALE);
        }

        if (is_numeric($accelerated)) {
            $unit = bcsub($unit, (string) $accelerated, DecimalRounder::CALCULATION_SCALE);
        }

        return [$this->rounder->round($unit, DecimalRounder::UNIT_SCALE), $this->moneyFromUnit($unit, (string) $row->quantity)];
    }

    /**
     * Prêmio informado e amortização extraordinária informada, pela data do
     * pagamento (efetiva; ou a contratual, quando a convenção a deslocou).
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<string, EmissionPuDailyCurve>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function attachInformedSchedule(array $entries, Collection $payments, Collection $rows, ?string $realizedThrough): array
    {
        $byDate = $payments->groupBy(fn (Payment $payment): string => $this->date($payment->payment_date));
        $activeDue = [];

        foreach ($entries as $entry) {
            if ($entry['lifecycle'] === PuObligationLifecycle::Active) {
                $activeDue[(string) $entry['due_date']][] = $entry['type'];
            }
        }

        $used = [];

        foreach ($entries as $index => $entry) {
            $due = (string) $entry['due_date'];
            $contractual = (string) $entry['contractual_date'];
            $date = null;

            if ($entry['type'] === PuObligationType::ScheduledPayment) {
                if ($byDate->has($due)) {
                    $date = $due;
                } elseif ($contractual !== $due && $byDate->has($contractual) && ! isset($activeDue[$contractual])) {
                    $date = $contractual;
                }
            } elseif ($byDate->has($due)
                && ! in_array(PuObligationType::ScheduledPayment, $activeDue[$due] ?? [], true)
                && count($activeDue[$due] ?? []) === 1) {
                $date = $due;
            }

            if ($date === null) {
                continue;
            }

            $used[$date] = true;

            if ($entry['lifecycle'] !== PuObligationLifecycle::Active) {
                continue;
            }

            $informedRows = $byDate->get($date);
            $informed = [];

            if ($informedRows->count() > 1) {
                if ($informedRows->contains(fn (Payment $payment): bool => $this->positive($payment->premium_value) || $this->positive($payment->extra_amortization_value))) {
                    $informed[] = $this->unsupported(
                        PuObligationComponent::Premium,
                        PuObligationComponentOwner::InformedSchedule,
                        sprintf('Há %d linhas no cronograma informado em %s: não se sabe qual prêmio ou amortização extraordinária vale.', $informedRows->count(), CarbonImmutable::parse($date)->format('d/m/Y')),
                        ['payment_ids' => $informedRows->pluck('id')->all()],
                    );
                }

                $entries[$index]['informed'] = $informed;

                continue;
            }

            /** @var Payment $payment */
            $payment = $informedRows->first();
            $entries[$index]['payment_id'] = (int) $payment->id;
            $row = $entry['row'] ?? $rows->get($due);
            $source = ['payment_id' => (int) $payment->id, 'payment_date' => $date];

            if ($this->positive($payment->premium_value)) {
                $memory = $row instanceof EmissionPuDailyCurve && is_array($row->calculation_memory) ? $row->calculation_memory : [];

                $informed[] = ($memory['first_coupon_pre_integralization_premium_applied'] ?? false) === true
                    ? $this->unsupported(
                        PuObligationComponent::Premium,
                        PuObligationComponentOwner::InformedSchedule,
                        sprintf(
                            'O cronograma informa prêmio de R$ %s nesta data, mas a curva oficial já incorporou o prêmio de primeiro cupom aos juros: somar os dois contaria o prêmio duas vezes. Confirme no contrato se são o mesmo valor.',
                            (string) $payment->premium_value,
                        ),
                        [...$source, 'informed_amount' => (string) $payment->premium_value],
                    )
                    : new PuObligationComponentData(
                        component: PuObligationComponent::Premium,
                        owner: PuObligationComponentOwner::InformedSchedule,
                        status: PuObligationComponentStatus::Informed,
                        amount: $this->rounder->round((string) $payment->premium_value, DecimalRounder::LEGACY_MONEY_SCALE),
                        source: $source,
                    );
            }

            if ($this->positive($payment->extra_amortization_value)) {
                $informed[] = $this->unsupported(
                    PuObligationComponent::ExtraordinaryAmortization,
                    PuObligationComponentOwner::InformedSchedule,
                    sprintf(
                        'O cronograma informa amortização extraordinária de R$ %s nesta data sem evento contratual de amortização extraordinária: a curva oficial não reduziu o principal por ela. Cadastre o evento com a regra explícita.',
                        (string) $payment->extra_amortization_value,
                    ),
                    [...$source, 'informed_amount' => (string) $payment->extra_amortization_value],
                );
            }

            $entries[$index]['informed'] = $informed;
        }

        $unmatched = $payments
            ->map(fn (Payment $payment): string => $this->date($payment->payment_date))
            ->filter(fn (string $date): bool => ! isset($used[$date]) && $realizedThrough !== null && $date <= $realizedThrough)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [$entries, $unmatched];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function draft(array $entry): PuObligationDraft
    {
        $lifecycle = $entry['lifecycle'];
        $state = $entry['state'];
        $reason = $entry['state_reason'] ?? null;
        $engine = $entry['engine'] ?? null;
        $components = [];

        if ($lifecycle === PuObligationLifecycle::Active && is_array($engine)) {
            $components = [...$engine, ...($entry['informed'] ?? [])];
            $unsupported = array_values(array_filter($components, fn (PuObligationComponentData $component): bool => $component->isUnsupported()));

            if ($state === PuObligationCalculationState::Calculated && $unsupported !== []) {
                $state = PuObligationCalculationState::Unsupported;
                $reason = $unsupported[0]->reason;
            }
        }

        return new PuObligationDraft(
            type: $entry['type'],
            contractualDate: (string) $entry['contractual_date'],
            sequence: (int) $entry['sequence'],
            dueDate: (string) $entry['due_date'],
            lifecycle: $lifecycle,
            state: $state,
            stateReason: $reason,
            components: $components,
            eventIds: array_values(array_map('intval', $entry['event_ids'])),
            paymentId: $entry['payment_id'] ?? null,
            supersessionReason: $entry['supersession_reason'] ?? null,
            supersededByKey: $entry['superseded_by_key'] ?? null,
        );
    }

    /**
     * @param  list<int|null>  $eventIds
     * @return array<string, mixed>
     */
    private function entry(PuObligationType $type, string $contractual, int $sequence, string $due, array $eventIds): array
    {
        return [
            'type' => $type,
            'contractual_date' => $contractual,
            'sequence' => $sequence,
            'due_date' => $due,
            'lifecycle' => PuObligationLifecycle::Active,
            'event_ids' => array_values(array_filter($eventIds, fn (?int $id): bool => $id !== null)),
            'state' => PuObligationCalculationState::AwaitingIndex,
            'state_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function supersededByEarlyMaturity(array $entry, ?string $earlyMaturityKey): array
    {
        return [
            ...$entry,
            'lifecycle' => PuObligationLifecycle::Superseded,
            'state' => PuObligationCalculationState::NoOfficialCalculation,
            'state_reason' => 'Superada pelo vencimento antecipado: a operação termina antes desta data.',
            'supersession_reason' => 'superseded_by_early_maturity',
            'superseded_by_key' => $earlyMaturityKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function unsupported(PuObligationComponent $component, PuObligationComponentOwner $owner, string $reason, array $source): PuObligationComponentData
    {
        return new PuObligationComponentData(
            component: $component,
            owner: $owner,
            status: PuObligationComponentStatus::Unsupported,
            amount: null,
            reason: $reason,
            source: $source,
        );
    }

    /**
     * Valor financeiro da linha (16 casas) na escala monetária canônica: 2 casas,
     * meio para cima -- a mesma regra que o cronograma sempre usou.
     */
    private function money(string $value): string
    {
        return $this->rounder->round($value, DecimalRounder::LEGACY_MONEY_SCALE);
    }

    /**
     * Valor unitário × quantidade com a mesma sequência da engine (16 casas) e depois
     * a escala monetária.
     */
    private function moneyFromUnit(string $unit, string $quantity): string
    {
        return $this->money($this->rounder->round(
            bcmul($unit, $quantity, DecimalRounder::CALCULATION_SCALE + 4),
            DecimalRounder::TOTAL_SCALE,
        ));
    }

    private function positive(mixed $value): bool
    {
        return $value !== null && bccomp((string) $value, '0', 2) === 1;
    }

    private function date(mixed $value): string
    {
        return CarbonImmutable::parse((string) ($value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value))->toDateString();
    }
}
