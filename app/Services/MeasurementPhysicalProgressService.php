<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementPhysicalProgressContribution;
use App\Enums\MeasurementRevisionStatus;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Fonte única do progresso físico da Medição.
 *
 * O acumulado deixou de ser "o acumulado da linha anterior do cronograma":
 * uma linha não medida guarda zero e zerava a base do mês seguinte, e uma
 * aprovação que deixou de valer continuava gravada na linha. Agora ele é
 * derivado, a cada leitura, de dados que não envelhecem:
 *
 * - o avanço inicial do plano, imutável depois da criação;
 * - o `engineering_snapshot` de cada medição cuja Engenharia está vigente
 *   (revisão da etapa 1 aprovada -- o mesmo predicado de todas as guardas do
 *   módulo). Devolver a medição à Engenharia ou recusá-la tira a contribuição
 *   sem apagar nada; reaprová-la gera um snapshot novo, que conta uma vez.
 *
 * A composição não consulta o banco; as fontes vêm de {@see self::sources()}.
 * Dentro da aprovação da Engenharia a mesma leitura comum serve porque roda
 * depois do lock da Operation, primeira instrução da transação: no MySQL em
 * REPEATABLE READ a fotografia da transação nasce depois desse lock e já
 * enxerga a aprovação anterior da mesma operação, e nenhuma outra pode
 * commitar enquanto ele durar. Uma devolução à Engenharia em paralelo só tira
 * contribuição -- a conta fica conservadora, nunca acima do teto.
 */
class MeasurementPhysicalProgressService
{
    /**
     * Progresso de cada plano da operação, por leitura comum.
     *
     * @return array<int, MeasurementPhysicalProgress> indexado por `plan_set_id`
     */
    public function forOperation(Operation|int $operation, ?int $excludingMeasurementId = null): array
    {
        $operationId = $operation instanceof Operation ? (int) $operation->getKey() : $operation;

        $planSets = MeasurementPlanSet::query()
            ->where('operation_id', $operationId)
            ->orderBy('id')
            ->get();

        return $this->compose($this->sources($operationId), $planSets, $excludingMeasurementId);
    }

    public function forPlanSet(MeasurementPlanSet $planSet, ?int $excludingMeasurementId = null): MeasurementPhysicalProgress
    {
        return $this->forOperation((int) $planSet->operation_id, $excludingMeasurementId)[(int) $planSet->getKey()]
            ?? $this->compose(['snapshots' => [], 'legacy_lines' => new Collection, 'legacy_plan_sets' => []], new Collection([$planSet]))[(int) $planSet->getKey()];
    }

    /**
     * Junta as fontes ao avanço inicial de cada plano. Não consulta o banco.
     *
     * @param  array{snapshots: array<int, mixed>, legacy_lines: Collection<int, MeasurementPlanLine>, legacy_plan_sets: array<int, list<int>>, lineages?: array<int, string>}  $sources
     * @param  Collection<int, MeasurementPlanSet>  $planSets
     * @return array<int, MeasurementPhysicalProgress> indexado por `plan_set_id`
     */
    public function compose(array $sources, Collection $planSets, ?int $excludingMeasurementId = null): array
    {
        $progress = [];

        foreach ($planSets as $planSet) {
            $planSetId = (int) $planSet->getKey();
            $contributions = [];
            $unverified = [];

            foreach ($sources['snapshots'] as $measurementId => $snapshot) {
                $measurementId = (int) $measurementId;

                if ($measurementId === $excludingMeasurementId) {
                    continue;
                }

                $contribution = is_array($snapshot)
                    ? $this->snapshotContribution($measurementId, $planSetId, $snapshot, $sources['lineages'] ?? [])
                    : $this->legacyContribution($measurementId, $planSetId, $sources['legacy_lines'], $sources['legacy_plan_sets'][$measurementId] ?? []);

                if ($contribution === false) {
                    $unverified[] = $measurementId;
                } elseif ($contribution instanceof MeasurementPhysicalProgressContribution) {
                    $contributions[] = $contribution;
                }
            }

            usort($contributions, fn (MeasurementPhysicalProgressContribution $left, MeasurementPhysicalProgressContribution $right): int => [...MeasurementPhysicalProgress::position($left->measurementDate, $left->sequenceNumber), $left->measurementId]
                <=> [...MeasurementPhysicalProgress::position($right->measurementDate, $right->sequenceNumber), $right->measurementId]);

            $progress[$planSetId] = new MeasurementPhysicalProgress(
                planSetId: $planSetId,
                initialBasisPoints: MeasurementPhysicalProgress::basisPoints($planSet->initial_physical_progress_percent) ?? 0,
                initialReferenceDate: $planSet->initial_physical_progress_reference_date === null
                    ? null
                    : CarbonImmutable::parse($planSet->initial_physical_progress_reference_date->toDateString()),
                contributions: $contributions,
                unverifiedMeasurementIds: $unverified,
            );
        }

        return $progress;
    }

    /**
     * Snapshots das medições da operação com a Engenharia vigente e, para as
     * aprovadas antes do snapshot existir, as linhas que elas gravaram.
     *
     * Uma contribuição por medição lógica: só a revisão vigente conta. A
     * substituída guarda o snapshot dela como histórico, e a revisão em
     * análise só passa a contar quando substitui a vigente -- nunca as duas
     * juntas. A medição sem revisão é sempre a vigente.
     *
     * @return array{snapshots: array<int, mixed>, legacy_lines: Collection<int, MeasurementPlanLine>, legacy_plan_sets: array<int, list<int>>, lineages: array<int, string>}
     */
    public function sources(int $operationId): array
    {
        $snapshots = Measurement::query()
            ->where('operation_id', $operationId)
            ->where('revision_status', MeasurementRevisionStatus::Effective->value)
            ->whereHas('reviews', fn ($reviews) => $reviews
                ->where('stage', MeasurementWorkflow::STAGE_ENGINEERING)
                ->where('status', 'approved'))
            ->orderBy('id')
            ->get(['id', 'engineering_snapshot'])
            ->mapWithKeys(fn (Measurement $measurement): array => [(int) $measurement->getKey() => $measurement->engineering_snapshot])
            ->all();

        return [
            'snapshots' => $snapshots,
            'legacy_lines' => $this->legacyLines($operationId, $snapshots),
            'legacy_plan_sets' => $this->legacyPlanSets($snapshots),
            'lineages' => $this->lineages($snapshots),
        ];
    }

    /**
     * Linhagem de cada linha que os snapshots citam: a contribuição medida numa
     * linha da V1 continua sendo da mesma medição prevista na cópia da V2. O
     * snapshot novo já traz a linhagem; os anteriores a ela são resolvidos
     * pela linha.
     *
     * @param  array<int, mixed>  $snapshots
     * @return array<int, string> lineage_key por plan_line_id
     */
    private function lineages(array $snapshots): array
    {
        $lineIds = [];

        foreach ($snapshots as $snapshot) {
            foreach (is_array($snapshot) && is_array($snapshot['plan_sets'] ?? null) ? $snapshot['plan_sets'] : [] as $entry) {
                if (is_array($entry) && filled($entry['plan_line_id'] ?? null)) {
                    $lineIds[] = (int) $entry['plan_line_id'];
                }
            }
        }

        if ($lineIds === []) {
            return [];
        }

        return MeasurementPlanLine::query()
            ->whereKey(array_values(array_unique($lineIds)))
            ->pluck('lineage_key', 'id')
            ->map(fn (mixed $key): string => (string) $key)
            ->all();
    }

    /**
     * Linhas gravadas por aprovações anteriores ao snapshot da Engenharia.
     *
     * Só são lidas para medições com Engenharia vigente e snapshot nulo: antes
     * de 2026-08-25 a aprovação gravava a linha e nada mais, e é ela o único
     * registro do que foi aprovado.
     *
     * @param  array<int, mixed>  $snapshots
     * @return Collection<int, MeasurementPlanLine>
     */
    private function legacyLines(int $operationId, array $snapshots): Collection
    {
        $legacyIds = array_keys(array_filter($snapshots, fn (mixed $snapshot): bool => ! is_array($snapshot)));

        if ($legacyIds === []) {
            return new Collection;
        }

        return MeasurementPlanLine::query()
            ->where('operation_id', $operationId)
            ->whereIn('measurement_id', $legacyIds)
            ->orderBy('id')
            ->get(['id', 'plan_set_id', 'operation_id', 'sequence_number', 'measurement_date', 'measurement_id', 'realized_monthly_percent', 'lineage_key'])
            ->sortBy([['sequence_number', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Planos que cada aprovação anterior ao snapshot cobria, pelos arquivos que
     * ela enviou -- para saber que ela cobria um plano mesmo quando não gravou
     * linha nenhuma nele.
     *
     * @param  array<int, mixed>  $snapshots
     * @return array<int, list<int>> plan_set_ids por measurement_id
     */
    private function legacyPlanSets(array $snapshots): array
    {
        $legacyIds = array_keys(array_filter($snapshots, fn (mixed $snapshot): bool => ! is_array($snapshot)));

        if ($legacyIds === []) {
            return [];
        }

        return MeasurementAsset::query()
            ->whereIn('measurement_id', $legacyIds)
            ->whereNotNull('plan_set_id')
            ->get(['measurement_id', 'plan_set_id'])
            ->groupBy('measurement_id')
            ->map(fn (Collection $assets): array => $assets->pluck('plan_set_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all())
            ->all();
    }

    /**
     * Contribuição registrada no snapshot; `null` quando a medição não cobre o
     * plano, `false` quando cobre mas o avanço não pode ser lido.
     *
     * @param  array<string, mixed>  $snapshot
     */
    /**
     * @param  array<int, string>  $lineages
     */
    private function snapshotContribution(int $measurementId, int $planSetId, array $snapshot, array $lineages = []): MeasurementPhysicalProgressContribution|false|null
    {
        $entry = collect(is_array($snapshot['plan_sets'] ?? null) ? $snapshot['plan_sets'] : [])
            ->first(fn (mixed $entry): bool => is_array($entry) && (int) ($entry['plan_set_id'] ?? 0) === $planSetId);

        if (! is_array($entry)) {
            return null;
        }

        $basisPoints = MeasurementPhysicalProgress::basisPoints($entry['realized_monthly_percent'] ?? null);

        if ($basisPoints === null || $basisPoints < 0) {
            return false;
        }

        $planLineId = filled($entry['plan_line_id'] ?? null) ? (int) $entry['plan_line_id'] : null;

        return new MeasurementPhysicalProgressContribution(
            measurementId: $measurementId,
            planSetId: $planSetId,
            planLineId: $planLineId,
            sequenceNumber: (int) ($entry['sequence_number'] ?? 0),
            measurementDate: $this->date($entry['measurement_date'] ?? null),
            basisPoints: $basisPoints,
            lineageKey: is_string($entry['plan_line_lineage_key'] ?? null)
                ? $entry['plan_line_lineage_key']
                : ($planLineId === null ? null : ($lineages[$planLineId] ?? null)),
        );
    }

    /**
     * @param  Collection<int, MeasurementPlanLine>  $legacyLines
     */
    /**
     * Contribuição de uma aprovação anterior ao snapshot, pela linha que ela
     * gravou. Se ela enviou arquivo para o plano e não há linha para ler, o
     * avanço dela é desconhecido: não verificável, e não "zero".
     *
     * @param  Collection<int, MeasurementPlanLine>  $legacyLines
     * @param  list<int>  $coveredPlanSetIds
     */
    private function legacyContribution(int $measurementId, int $planSetId, Collection $legacyLines, array $coveredPlanSetIds): MeasurementPhysicalProgressContribution|false|null
    {
        $line = $legacyLines->first(fn (MeasurementPlanLine $line): bool => (int) $line->measurement_id === $measurementId
            && (int) $line->plan_set_id === $planSetId);

        if (! $line instanceof MeasurementPlanLine) {
            return in_array($planSetId, $coveredPlanSetIds, true) ? false : null;
        }

        $basisPoints = MeasurementPhysicalProgress::basisPoints($line->realized_monthly_percent);

        if ($basisPoints === null || $basisPoints < 0) {
            return false;
        }

        return new MeasurementPhysicalProgressContribution(
            measurementId: $measurementId,
            planSetId: $planSetId,
            planLineId: (int) $line->getKey(),
            sequenceNumber: (int) $line->sequence_number,
            measurementDate: $line->measurement_date === null ? null : CarbonImmutable::parse($line->measurement_date->toDateString()),
            basisPoints: $basisPoints,
            legacy: true,
            lineageKey: $line->lineage_key,
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
