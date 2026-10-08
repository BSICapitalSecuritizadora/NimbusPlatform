<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveChangeAssessment;
use App\Domain\PuCalculator\DTOs\PuCurveInputChange;
use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuCurveChangeKind;
use App\Domain\PuCalculator\Support\PuCurveChangePolicy;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Carbon\CarbonImmutable;

/**
 * Classificador central de impacto: compara o retrato APROVADO de uma versão com
 * os insumos contratuais vivos e responde se a versão ainda vale como está, se só
 * pode avançar até a véspera de uma mudança futura, ou se o passado gravado deixou
 * de ser reproduzível.
 *
 * Cada diferença ganha a data a partir da qual altera o cálculo:
 *
 *  - identidade da engine e termos de base: o início da curva;
 *  - vencimento/horizonte: o dia seguinte ao menor dos dois fins;
 *  - evento: a data efetiva (num evento movido, a menor das duas);
 *  - integralização: a data, nunca antes do início da curva.
 *
 * A decisão de cada diferença é da {@see PuCurveChangePolicy}; a da versão é a mais
 * restritiva delas. Extensão, homologação, atualidade e auditoria chamam este
 * serviço -- ninguém compara retratos por conta própria.
 */
final class PuCurveChangeImpactClassifier
{
    public function __construct(
        private readonly PuCurveInputSnapshotService $snapshots,
    ) {}

    public function compare(
        PuCurveInputSnapshot $approved,
        PuCurveInputSnapshot $live,
        ?CarbonImmutable $lastPersistedDate,
    ): PuCurveChangeAssessment {
        if ($approved->sameInputsAs($live)) {
            return PuCurveChangeAssessment::unchanged($approved->fingerprint, $lastPersistedDate);
        }

        $start = $this->earlier($approved->curveStartDate(), $live->curveStartDate());
        $changes = [
            ...$this->engineChanges($approved, $live, $start, $lastPersistedDate),
            ...$this->termChanges($approved, $live, $start, $lastPersistedDate),
            ...$this->horizonChanges($approved, $live, $lastPersistedDate),
            ...$this->eventChanges($approved, $live, $lastPersistedDate),
            ...$this->integralizationChanges($approved, $live, $start, $lastPersistedDate),
        ];

        if ($changes === []) {
            // Fingerprints diferentes sem diferença estrutural só acontece com formato
            // novo: tratado como troca de engine, na dúvida o passado.
            $changes[] = $this->change(PuCurveChangeKind::EngineIdentity, 'schema', $start, $approved->schema, $live->schema, $lastPersistedDate);
        }

        $earliest = null;

        foreach ($changes as $change) {
            if ($change->affectedFrom !== null && ($earliest === null || $change->affectedFrom->lt($earliest))) {
                $earliest = $change->affectedFrom;
            }
        }

        return new PuCurveChangeAssessment(
            impact: PuCurveChangePolicy::combine(array_map(fn (PuCurveInputChange $change): PuCurveChangeImpact => $change->impact, $changes)),
            earliestAffectedDate: $earliest,
            changes: $changes,
            approvedFingerprint: $approved->fingerprint,
            liveFingerprint: $live->fingerprint,
            lastPersistedDate: $lastPersistedDate,
        );
    }

    /**
     * A versão contra os insumos vivos da emissão. Nulo quando a versão não tem
     * retrato (anterior à Fase 4): não há o que comparar, e quem chama decide
     * falhar fechado.
     */
    public function assessVersion(
        EmissionPuCurveVersion $version,
        ?PuCurveInputSnapshot $live = null,
        bool $lockForShare = false,
    ): ?PuCurveChangeAssessment {
        $approved = $this->snapshots->forVersion($version);

        if (! $approved instanceof PuCurveInputSnapshot) {
            return null;
        }

        $emission = Emission::query()->findOrFail($version->emission_id);
        $live ??= $this->snapshots->capture($emission, $lockForShare);

        return $this->compare($approved, $live, $this->lastPersistedDate($version));
    }

    public function lastPersistedDate(EmissionPuCurveVersion $version): ?CarbonImmutable
    {
        $last = EmissionPuDailyCurve::query()
            ->where('curve_version_id', $version->id)
            ->max('curve_date');

        return $last !== null ? CarbonImmutable::parse((string) $last)->startOfDay() : null;
    }

    /**
     * @return list<PuCurveInputChange>
     */
    private function engineChanges(
        PuCurveInputSnapshot $approved,
        PuCurveInputSnapshot $live,
        CarbonImmutable $start,
        ?CarbonImmutable $lastPersisted,
    ): array {
        $changes = [];

        foreach ($this->keys($approved->engine(), $live->engine()) as $key) {
            $before = $approved->engine()[$key] ?? null;
            $after = $live->engine()[$key] ?? null;

            if ($before !== $after) {
                $changes[] = $this->change(PuCurveChangeKind::EngineIdentity, 'engine.'.$key, $start, $before, $after, $lastPersisted);
            }
        }

        return $changes;
    }

    /**
     * @return list<PuCurveInputChange>
     */
    private function termChanges(
        PuCurveInputSnapshot $approved,
        PuCurveInputSnapshot $live,
        CarbonImmutable $start,
        ?CarbonImmutable $lastPersisted,
    ): array {
        $changes = [];

        foreach ($this->keys($approved->terms(), $live->terms()) as $key) {
            $before = $approved->terms()[$key] ?? null;
            $after = $live->terms()[$key] ?? null;

            if ($before !== $after) {
                $changes[] = $this->change(PuCurveChangeKind::ContractualTerms, 'terms.'.$key, $start, $before, $after, $lastPersisted);
            }
        }

        $beforeStart = $approved->horizon()['curve_start_date'] ?? null;
        $afterStart = $live->horizon()['curve_start_date'] ?? null;

        if ($beforeStart !== $afterStart) {
            $changes[] = $this->change(PuCurveChangeKind::ContractualTerms, 'horizon.curve_start_date', $start, $beforeStart, $afterStart, $lastPersisted);
        }

        return $changes;
    }

    /**
     * Mudar o fim da curva não altera nenhum dia anterior ao menor dos dois fins.
     *
     * @return list<PuCurveInputChange>
     */
    private function horizonChanges(
        PuCurveInputSnapshot $approved,
        PuCurveInputSnapshot $live,
        ?CarbonImmutable $lastPersisted,
    ): array {
        $changes = [];

        foreach (['contractual_maturity_date', 'curve_end_date'] as $key) {
            $before = $approved->horizon()[$key] ?? null;
            $after = $live->horizon()[$key] ?? null;

            if ($before === $after) {
                continue;
            }

            $affected = $this->earlier(
                CarbonImmutable::parse((string) ($before ?? $after)),
                CarbonImmutable::parse((string) ($after ?? $before)),
            )->addDay();
            $changes[] = $this->change(PuCurveChangeKind::Horizon, 'horizon.'.$key, $affected, $before, $after, $lastPersisted);
        }

        return $changes;
    }

    /**
     * Eventos casados pela identidade (tipo, data efetiva, sequência). Um evento que
     * muda de data aparece como saída na data antiga e entrada na nova.
     *
     * @return list<PuCurveInputChange>
     */
    private function eventChanges(
        PuCurveInputSnapshot $approved,
        PuCurveInputSnapshot $live,
        ?CarbonImmutable $lastPersisted,
    ): array {
        $before = $this->eventsByIdentity($approved->events());
        $after = $this->eventsByIdentity($live->events());
        $changes = [];

        foreach ($this->keys($before, $after) as $identity) {
            $old = $before[$identity] ?? null;
            $new = $after[$identity] ?? null;

            if ($old === $new) {
                continue;
            }

            $date = (string) (($old ?? $new)['effective_date']);
            $changes[] = $this->change(
                PuCurveChangeKind::ContractualEvent,
                'event.'.$identity,
                CarbonImmutable::parse($date)->startOfDay(),
                $old,
                $new,
                $lastPersisted,
            );
        }

        return $changes;
    }

    /**
     * @return list<PuCurveInputChange>
     */
    private function integralizationChanges(
        PuCurveInputSnapshot $approved,
        PuCurveInputSnapshot $live,
        CarbonImmutable $start,
        ?CarbonImmutable $lastPersisted,
    ): array {
        $before = $this->quantitiesByDate($approved->integralizations());
        $after = $this->quantitiesByDate($live->integralizations());
        $changes = [];

        foreach ($this->keys($before, $after) as $date) {
            $old = $before[$date] ?? null;
            $new = $after[$date] ?? null;

            if ($old === $new) {
                continue;
            }

            $affected = CarbonImmutable::parse((string) $date)->startOfDay();
            $changes[] = $this->change(
                PuCurveChangeKind::Integralization,
                'integralization.'.$date,
                $affected->lt($start) ? $start : $affected,
                $old,
                $new,
                $lastPersisted,
            );
        }

        return $changes;
    }

    private function change(
        PuCurveChangeKind $kind,
        string $key,
        ?CarbonImmutable $affectedFrom,
        mixed $before,
        mixed $after,
        ?CarbonImmutable $lastPersisted,
    ): PuCurveInputChange {
        return new PuCurveInputChange(
            kind: $kind,
            key: $key,
            affectedFrom: $affectedFrom,
            before: $before,
            after: $after,
            impact: PuCurveChangePolicy::decide($kind, $affectedFrom, $lastPersisted),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, array<string, mixed>>
     */
    private function eventsByIdentity(array $events): array
    {
        $indexed = [];

        foreach ($events as $event) {
            $indexed[PuCurveInputSnapshot::eventIdentity($event)] = PuCurveInputSnapshot::canonicalize($event);
        }

        return $indexed;
    }

    /**
     * @param  list<array{date: string, quantity: string}>  $timeline
     * @return array<string, string>
     */
    private function quantitiesByDate(array $timeline): array
    {
        $indexed = [];

        foreach ($timeline as $entry) {
            $indexed[(string) $entry['date']] = (string) $entry['quantity'];
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return list<string>
     */
    private function keys(array $left, array $right): array
    {
        $keys = array_values(array_unique([...array_map('strval', array_keys($left)), ...array_map('strval', array_keys($right))]));
        sort($keys);

        return $keys;
    }

    private function earlier(CarbonImmutable $left, CarbonImmutable $right): CarbonImmutable
    {
        return $left->lte($right) ? $left->startOfDay() : $right->startOfDay();
    }
}
