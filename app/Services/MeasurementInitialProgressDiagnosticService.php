<?php

namespace App\Services;

use App\Enums\MeasurementInitialProgressClassification as Classification;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnóstico somente leitura do avanço físico inicial legado: a decisão que a
 * migração 2026_10_05_170828 toma em cada plano de medição (antes de ela rodar)
 * ou tomou (depois), e se o plano ainda confere com a trilha que ela gravou.
 *
 * A regra é reimplementada em {@see self::decide()} com a semântica exata da
 * migration: "Realiz. inicial" da 1ª linha válido só em (0, 100], bcmath com
 * duas casas sem passar por float, valor ilegível lido como 0 (e o não
 * escalar, em que ela quebra, apontado), aprovações vigentes da operação do
 * plano em ordem de medição e a precedência sem aprovação > aprovação que
 * partiu de base menor > acima de 100%. A migration
 * não é editada para chamar esta classe: já commitada, ela é o registro
 * executável do que roda em produção, e a trilha aponta para ela
 * (`properties.migration`). O serviço vivo do avanço físico também não serve --
 * diverge dela nas bordas (mensal negativo, por exemplo, ele trata como não
 * verificável). O teste de paridade roda a migration real numa matriz de
 * cenários e cobra previsão = decisão gravada.
 *
 * A fase vem do esquema. Sem as colunas novas, o relatório prevê a decisão; com
 * elas, mostra a decisão gravada, confere a trilha com o avanço inicial do plano
 * e aponta o que mudou desde a decisão.
 *
 * Só lê: query builder, sem models (que disparariam eventos e trilha) e sem
 * lock. No MySQL, numa transação READ ONLY ({@see self::readOnly()}).
 *
 * @phpstan-type FirstLine array{id: int, sequence_number: int, initial: mixed}
 * @phpstan-type ApprovedEntry array{measurement_id: int, plan_line_id: int, monthly: mixed, cumulative: mixed, reviewed_at?: string|null}
 * @phpstan-type Decision array{outcome: string, classification: Classification, reason: string|null, plan_line_id: int|null, sequence_number: int|null, line_initial_percent: string|null, measured_percent: string|null, proven_by_measurement_id: int|null, contradicted_by_measurement_id: int|null, migration_would_fail_measurement_ids: list<int>}
 * @phpstan-type RecordedDecision array{activity_id: int, description: string, classification: Classification|null, reason: mixed, plan_line_id: int|null, sequence_number: int|null, line_initial_percent: mixed, measured_percent: mixed, proven_by_measurement_id: int|null, contradicted_by_measurement_id: int|null, decided_at: string}
 * @phpstan-type Baseline array{percent: string, reference_date: string|null}
 * @phpstan-type LineSummary array{id: int, sequence_number: int, initial_percent: string}
 * @phpstan-type PlanReport array{operation_id: int, operation_code: string|null, plan_set_id: int, plan_name: string, first_line: LineSummary|null, other_lines_with_initial: list<LineSummary>, current_approvals: int, unreadable_approval_measurement_ids: list<int>, migration_would_fail_measurement_ids: list<int>, baseline: Baseline|null, classification: Classification, prediction: Decision|null, recorded: RecordedDecision|null, reevaluated: Decision|null, drift: bool, issues: list<string>, consistent: bool|null}
 * @phpstan-type Connection array{driver: string, host: string|null, port: string|null, database: string}
 * @phpstan-type Report array{phase: string, connection: Connection, missing_operation_ids: list<int>, consistent: bool|null, migration_would_fail_plan_ids: list<int>, summary: array<string, int>, plans: list<PlanReport>}
 */
class MeasurementInitialProgressDiagnosticService
{
    public const MIGRATION = '2026_10_05_170828_add_initial_physical_progress_to_measurement_plan_sets_table';

    public const PHASE_BEFORE_MIGRATION = 'pre_migration';

    public const PHASE_AFTER_MIGRATION = 'post_migration';

    public const OUTCOME_BACKFILLED = 'backfilled';

    public const OUTCOME_NOT_BACKFILLED = 'not_backfilled';

    /**
     * A migração passa pelo plano sem decidir nem deixar trilha.
     */
    public const OUTCOME_SKIPPED = 'skipped';

    /**
     * Plano que a migração precisava decidir, sem decisão gravada.
     */
    public const ISSUE_DECISION_MISSING = 'decision_missing';

    /**
     * Avanço inicial sem data que nenhuma decisão explica: nem a criação do plano
     * (que exige a data) nem a migração (que sempre deixa trilha) o gravam.
     */
    public const ISSUE_BASELINE_WITHOUT_TRAIL = 'baseline_without_trail';

    public const ISSUE_DUPLICATE_TRAIL = 'duplicate_trail';

    public const ISSUE_TRAIL_INCONSISTENT = 'trail_inconsistent';

    public const ISSUE_BASELINE_DIFFERS_FROM_TRAIL = 'baseline_differs_from_trail';

    /**
     * O `subject_type` que a migração grava, literal como lá.
     */
    private const PLAN_SUBJECT_TYPE = 'App\Models\MeasurementPlanSet';

    /**
     * Categoria retida por sete anos em que a migração grava a trilha; fora dela,
     * a retenção a descartaria e o plano voltaria a parecer não decidido.
     */
    private const PROTECTED_LOG = 'measurements';

    /**
     * A Engenharia, congelada como na migração: vigente é a análise desta etapa
     * aprovada.
     */
    private const ENGINEERING_STAGE = 1;

    /**
     * @param  list<int>  $operationIds  operações a relatar; vazia, todas
     * @return Report
     */
    public function report(array $operationIds = []): array
    {
        return $this->readOnly(function () use ($operationIds): array {
            $phase = $this->phase();
            $missing = $this->missingOperationIds($operationIds);
            $plans = [];

            if ($missing === []) {
                foreach ($this->planRowsByOperation($operationIds, $phase) as $operationId => $planRows) {
                    array_push($plans, ...$this->operationPlans((int) $operationId, $planRows, $phase));
                }
            }

            return [
                'phase' => $phase,
                'connection' => $this->connection(),
                'missing_operation_ids' => $missing,
                'consistent' => $phase === self::PHASE_AFTER_MIGRATION && $missing === []
                    ? ! collect($plans)->contains(fn (array $plan): bool => $plan['issues'] !== [])
                    : null,
                'migration_would_fail_plan_ids' => array_values(array_map(
                    fn (array $plan): int => $plan['plan_set_id'],
                    array_filter($plans, fn (array $plan): bool => $plan['migration_would_fail_measurement_ids'] !== []),
                )),
                'summary' => $this->summary($plans),
                'plans' => $plans,
            ];
        });
    }

    /**
     * De onde o relatório leu, para que o arquivo entregue ao dono prove a
     * origem. Com configuração em cache o Laravel ignora as variáveis DB_* do
     * shell sem aviso, e uma execução "contra produção" leria outra base em
     * silêncio. Só a configuração da conexão: nenhuma consulta (o SET
     * TRANSACTION continua sendo a 1ª instrução) e nenhuma credencial.
     *
     * @return Connection
     */
    private function connection(): array
    {
        $connection = DB::connection();
        $configuredHost = $connection->getConfig('host');
        $port = $connection->getConfig('port');
        $hosts = array_filter(
            is_array($configuredHost) ? $configuredHost : [$configuredHost],
            fn (mixed $host): bool => is_scalar($host) && (string) $host !== '',
        );

        return [
            'driver' => $connection->getDriverName(),
            'host' => $hosts === [] ? null : implode(', ', $hosts),
            'port' => is_scalar($port) && (string) $port !== '' ? (string) $port : null,
            'database' => (string) $connection->getDatabaseName(),
        ];
    }

    /**
     * A decisão que a migração toma sobre um plano ainda sem avanço inicial nem
     * decisão gravada -- a mesma conta, sem banco.
     *
     * `measured_percent` repete até a semente da migração: '0', e não '0.00',
     * quando nenhuma aprovação vigente contribui.
     *
     * A migração converte em texto o mensal e o acumulado de cada aprovação, e
     * um valor não escalar -- uma lista no snapshot -- quebra a conversão: com o
     * tratamento de erros do Laravel ela lança "Array to string conversion", o
     * `migrate` para nesse plano e não grava decisão. Essas medições saem em
     * `migration_would_fail_measurement_ids`; o resto da conta as lê como 0 só
     * para o plano ter classificação, que não vale até o dado ser corrigido.
     * Plano que a migração pula não lê aprovação nenhuma, e nada quebra nele.
     *
     * @param  FirstLine|null  $firstLine  a linha de menor sequência e, no empate, menor id
     * @param  list<ApprovedEntry>  $approvedEntries  o avanço das aprovações vigentes no plano, em ordem de medição
     * @return Decision
     */
    public function decide(?array $firstLine, array $approvedEntries): array
    {
        $initial = $firstLine === null ? null : $this->percentWithinRange($firstLine['initial']);

        if ($firstLine === null || $initial === null) {
            return [
                'outcome' => self::OUTCOME_SKIPPED,
                'classification' => $firstLine === null || $this->isZeroOrBlank($firstLine['initial'])
                    ? Classification::NoLegacyInitialProgress
                    : Classification::LegacyInitialOutOfRange,
                'reason' => null,
                'plan_line_id' => $firstLine['id'] ?? null,
                'sequence_number' => $firstLine['sequence_number'] ?? null,
                'line_initial_percent' => null,
                'measured_percent' => null,
                'proven_by_measurement_id' => null,
                'contradicted_by_measurement_id' => null,
                'migration_would_fail_measurement_ids' => [],
            ];
        }

        $contributions = array_map(fn (array $entry): array => $this->contribution($entry), $approvedEntries);
        $measured = array_reduce($contributions, fn (string $sum, array $contribution): string => bcadd($sum, $contribution['monthly'], 2), '0');
        $contradicting = array_values(array_filter($contributions, fn (array $contribution): bool => bccomp($contribution['base'], $initial, 2) < 0));

        $reason = match (true) {
            $contributions === [] => 'no_current_approval',
            $contradicting !== [] => 'approval_started_below_initial',
            bccomp(bcadd($initial, $measured, 2), '100', 2) > 0 => 'initial_plus_measured_above_100',
            default => null,
        };

        return [
            'outcome' => $reason === null ? self::OUTCOME_BACKFILLED : self::OUTCOME_NOT_BACKFILLED,
            'classification' => Classification::fromMigrationReason($reason),
            'reason' => $reason,
            'plan_line_id' => $firstLine['id'],
            'sequence_number' => $firstLine['sequence_number'],
            'line_initial_percent' => $initial,
            'measured_percent' => $measured,
            'proven_by_measurement_id' => $reason === null ? $contributions[0]['measurement_id'] : null,
            'contradicted_by_measurement_id' => $contradicting[0]['measurement_id'] ?? null,
            'migration_would_fail_measurement_ids' => $this->unconvertibleMeasurementIds($approvedEntries),
        ];
    }

    /**
     * No MySQL, a leitura inteira corre numa transação READ ONLY: o próprio
     * servidor recusa qualquer escrita (erro 1792), e em REPEATABLE READ todas
     * as consultas veem a mesma fotografia enquanto a aplicação atende. A
     * transação é sempre desfeita no fim.
     *
     * Só com nenhuma transação aberta: dentro de uma, o SET TRANSACTION é
     * recusado (erro 1568), e abrir outra por fora faria commit implícito da que
     * estava aberta -- a do RefreshDatabase, nos testes. Nesse caso, e no
     * SQLite, a garantia é só a do código, que nunca escreve. Se a conexão cair
     * entre as duas instruções e o Laravel reconectar, a transação reaberta já
     * não é READ ONLY; continua valendo a mesma garantia do código.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    private function readOnly(Closure $read): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql'
            || $connection->transactionLevel() > 0
            || $connection->getPdo()->inTransaction()) {
            return $read();
        }

        $connection->statement('SET TRANSACTION READ ONLY');
        $connection->beginTransaction();

        try {
            return $read();
        } finally {
            $connection->rollBack();
        }
    }

    private function phase(): string
    {
        return Schema::hasColumns('measurement_plan_sets', ['initial_physical_progress_percent', 'initial_physical_progress_reference_date'])
            ? self::PHASE_AFTER_MIGRATION
            : self::PHASE_BEFORE_MIGRATION;
    }

    /**
     * @param  list<int>  $operationIds
     * @return list<int>
     */
    private function missingOperationIds(array $operationIds): array
    {
        if ($operationIds === []) {
            return [];
        }

        $existing = DB::table('operations')
            ->whereIn('id', $operationIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_diff($operationIds, $existing));
    }

    /**
     * @param  list<int>  $operationIds
     * @return Collection<int, Collection<int, object>>
     */
    private function planRowsByOperation(array $operationIds, string $phase): Collection
    {
        $columns = ['plan_set.id', 'plan_set.operation_id', 'plan_set.name', 'operation.code as operation_code'];

        if ($phase === self::PHASE_AFTER_MIGRATION) {
            array_push($columns, 'plan_set.initial_physical_progress_percent', 'plan_set.initial_physical_progress_reference_date');
        }

        return DB::table('measurement_plan_sets as plan_set')
            ->leftJoin('operations as operation', 'operation.id', '=', 'plan_set.operation_id')
            ->when($operationIds !== [], fn ($query) => $query->whereIn('plan_set.operation_id', $operationIds))
            ->orderBy('plan_set.operation_id')
            ->orderBy('plan_set.id')
            ->get($columns)
            ->groupBy(fn (object $row): int => (int) $row->operation_id);
    }

    /**
     * Os planos de uma operação, lidos em lote: a migração decide cada plano
     * olhando as aprovações da operação inteira.
     *
     * @param  Collection<int, object>  $planRows
     * @return list<PlanReport>
     */
    private function operationPlans(int $operationId, Collection $planRows, string $phase): array
    {
        $planIds = $planRows->map(fn (object $row): int => (int) $row->id)->values()->all();
        $linesByPlan = $this->linesByPlan($planIds);
        $approvals = $this->currentApprovals($operationId);
        $legacyLinesByMeasurement = $this->legacyApprovedLines($planIds, $approvals)
            ->groupBy(fn (object $line): int => (int) $line->measurement_id);
        $trailsByPlan = $this->trailsByPlan($planIds);

        return $planRows
            ->map(fn (object $row): array => $this->planReport(
                $row,
                $phase,
                $linesByPlan[(int) $row->id] ?? [],
                $this->approvedEntries((int) $row->id, $approvals, $legacyLinesByMeasurement),
                $trailsByPlan[(int) $row->id] ?? [],
            ))
            ->values()
            ->all();
    }

    /**
     * Linhas dos planos na ordem em que a migração escolhe a 1ª: sequência e,
     * no empate, id.
     *
     * @param  list<int>  $planIds
     * @return array<int, list<object>>
     */
    private function linesByPlan(array $planIds): array
    {
        $byPlan = [];

        $lines = DB::table('measurement_plan_lines')
            ->whereIn('plan_set_id', $planIds)
            ->orderBy('plan_set_id')
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->get(['id', 'plan_set_id', 'sequence_number', 'initial_realized_cumulative_percent']);

        foreach ($lines as $line) {
            $byPlan[(int) $line->plan_set_id][] = $line;
        }

        return $byPlan;
    }

    /**
     * Medições da operação com a Engenharia vigente, em ordem de id, como a
     * migração as lê.
     *
     * @return Collection<int, object>
     */
    private function currentApprovals(int $operationId): Collection
    {
        return DB::table('measurements')
            ->join('measurement_reviews', 'measurement_reviews.measurement_id', '=', 'measurements.id')
            ->where('measurements.operation_id', $operationId)
            ->where('measurement_reviews.stage', self::ENGINEERING_STAGE)
            ->where('measurement_reviews.status', 'approved')
            ->orderBy('measurements.id')
            ->get(['measurements.id', 'measurements.engineering_snapshot', 'measurement_reviews.reviewed_at']);
    }

    /**
     * Linhas gravadas pelas aprovações anteriores ao snapshot da Engenharia: para
     * elas, a linha é o único registro do avanço aprovado.
     *
     * @param  list<int>  $planIds
     * @param  Collection<int, object>  $approvals
     * @return Collection<int, object>
     */
    private function legacyApprovedLines(array $planIds, Collection $approvals): Collection
    {
        $legacyIds = $approvals
            ->filter(fn (object $approval): bool => $approval->engineering_snapshot === null)
            ->map(fn (object $approval): int => (int) $approval->id)
            ->values()
            ->all();

        if ($legacyIds === []) {
            return collect();
        }

        return DB::table('measurement_plan_lines')
            ->whereIn('plan_set_id', $planIds)
            ->whereIn('measurement_id', $legacyIds)
            ->orderBy('id')
            ->get(['id', 'plan_set_id', 'measurement_id', 'realized_monthly_percent', 'realized_cumulative_percent']);
    }

    /**
     * As decisões gravadas, em ordem de id: a primeira é a que vale, e a
     * migração não grava uma segunda.
     *
     * @param  list<int>  $planIds
     * @return array<int, list<object>>
     */
    private function trailsByPlan(array $planIds): array
    {
        $byPlan = [];

        $trails = DB::table('activity_log')
            ->where('subject_type', self::PLAN_SUBJECT_TYPE)
            ->whereIn('subject_id', $planIds)
            ->whereIn('description', [Classification::TRAIL_BACKFILLED, Classification::TRAIL_NOT_BACKFILLED])
            ->orderBy('id')
            ->get(['id', 'subject_id', 'log_name', 'description', 'properties', 'created_at']);

        foreach ($trails as $trail) {
            $byPlan[(int) $trail->subject_id][] = $trail;
        }

        return $byPlan;
    }

    /**
     * O avanço que as aprovações vigentes registraram no plano, em ordem de
     * medição: do snapshot da Engenharia ou, nas aprovações anteriores a ele,
     * da linha que gravaram. Os valores seguem crus; quem os lê é
     * {@see self::decide()}.
     *
     * @param  Collection<int, object>  $approvals
     * @param  Collection<int, Collection<int, object>>  $legacyLinesByMeasurement
     * @return list<ApprovedEntry>
     */
    private function approvedEntries(int $planSetId, Collection $approvals, Collection $legacyLinesByMeasurement): array
    {
        $entries = [];

        foreach ($approvals as $approval) {
            $measurementId = (int) $approval->id;
            $reviewedAt = $approval->reviewed_at === null ? null : (string) $approval->reviewed_at;

            if ($approval->engineering_snapshot === null) {
                foreach ($legacyLinesByMeasurement->get($measurementId, collect()) as $line) {
                    if ((int) $line->plan_set_id === $planSetId) {
                        $entries[] = [
                            'measurement_id' => $measurementId,
                            'plan_line_id' => (int) $line->id,
                            'monthly' => $line->realized_monthly_percent,
                            'cumulative' => $line->realized_cumulative_percent,
                            'reviewed_at' => $reviewedAt,
                        ];
                    }
                }

                continue;
            }

            $snapshot = json_decode((string) $approval->engineering_snapshot, true);

            foreach (is_array($snapshot['plan_sets'] ?? null) ? $snapshot['plan_sets'] : [] as $entry) {
                if (is_array($entry) && (int) ($entry['plan_set_id'] ?? 0) === $planSetId) {
                    $entries[] = [
                        'measurement_id' => $measurementId,
                        'plan_line_id' => (int) ($entry['plan_line_id'] ?? 0),
                        'monthly' => $entry['realized_monthly_percent'] ?? null,
                        'cumulative' => $entry['realized_cumulative_percent'] ?? null,
                        'reviewed_at' => $reviewedAt,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * A quebra prevista da migração só vale no plano que ela ainda vai decidir
     * (elegível): decidido ou com avanço informado na criação, ela o pula sem
     * ler aprovação nenhuma.
     *
     * @param  list<object>  $lines  linhas do plano, a 1ª primeiro
     * @param  list<ApprovedEntry>  $entries
     * @param  list<object>  $trails  decisões gravadas, em ordem de id
     * @return PlanReport
     */
    private function planReport(object $row, string $phase, array $lines, array $entries, array $trails): array
    {
        $firstLine = $lines === [] ? null : [
            'id' => (int) $lines[0]->id,
            'sequence_number' => (int) $lines[0]->sequence_number,
            'initial' => $lines[0]->initial_realized_cumulative_percent,
        ];
        $rule = $this->decide($firstLine, $entries);
        $baseline = $phase === self::PHASE_AFTER_MIGRATION ? [
            'percent' => $this->decimal($row->initial_physical_progress_percent),
            'reference_date' => $this->dateOf($row->initial_physical_progress_reference_date),
        ] : null;
        $recorded = $trails === [] ? null : $this->recordedDecision($trails[0]);
        $eligible = $trails === []
            && ($baseline === null || (bccomp($baseline['percent'], '0', 2) === 0 && $baseline['reference_date'] === null));
        $reevaluated = $recorded === null || $recorded['classification'] === null
            ? null
            : $this->decide($firstLine, $this->entriesApprovedUntil($entries, $recorded['decided_at']));
        $issues = $baseline === null ? [] : $this->integrityIssues($baseline, $trails, $recorded, $eligible, $rule);

        return [
            'operation_id' => (int) $row->operation_id,
            'operation_code' => $row->operation_code === null ? null : (string) $row->operation_code,
            'plan_set_id' => (int) $row->id,
            'plan_name' => (string) $row->name,
            'first_line' => $lines === [] ? null : $this->lineSummary($lines[0]),
            'other_lines_with_initial' => array_values(array_map(
                fn (object $line): array => $this->lineSummary($line),
                array_filter(array_slice($lines, 1), fn (object $line): bool => ! $this->isZeroOrBlank($line->initial_realized_cumulative_percent)),
            )),
            'current_approvals' => count(array_unique(array_column($entries, 'measurement_id'))),
            'unreadable_approval_measurement_ids' => $this->unreadableMeasurementIds($entries),
            'migration_would_fail_measurement_ids' => $eligible ? $rule['migration_would_fail_measurement_ids'] : [],
            'baseline' => $baseline,
            'classification' => $this->classification($rule, $baseline, $recorded, $eligible),
            'prediction' => $eligible ? $rule : null,
            'recorded' => $recorded,
            'reevaluated' => $reevaluated,
            'drift' => $recorded !== null && $reevaluated !== null && $this->drifted($reevaluated, $recorded),
            'issues' => $issues,
            'consistent' => $baseline === null ? null : $issues === [],
        ];
    }

    /**
     * Antes da migração vale a previsão; depois, a decisão gravada -- e, sem ela,
     * o que o próprio plano explica.
     *
     * @param  Decision  $rule
     * @param  Baseline|null  $baseline
     * @param  RecordedDecision|null  $recorded
     */
    private function classification(array $rule, ?array $baseline, ?array $recorded, bool $eligible): Classification
    {
        if ($recorded !== null) {
            return $recorded['classification'] ?? Classification::DecisionMissing;
        }

        if ($baseline === null) {
            return $rule['classification'];
        }

        if (! $eligible) {
            return $baseline['reference_date'] === null
                ? Classification::DecisionMissing
                : Classification::InitialInformedAtCreation;
        }

        return $rule['outcome'] === self::OUTCOME_SKIPPED ? $rule['classification'] : Classification::DecisionMissing;
    }

    /**
     * Divergências entre a trilha e o plano depois da migração. Cada uma derruba
     * o código de saída: ou a decisão não está onde a auditoria vai procurá-la,
     * ou o avanço inicial que vale nos cálculos não é o que ela registra.
     *
     * @param  Baseline  $baseline
     * @param  list<object>  $trails
     * @param  RecordedDecision|null  $recorded
     * @param  Decision  $rule
     * @return list<string>
     */
    private function integrityIssues(array $baseline, array $trails, ?array $recorded, bool $eligible, array $rule): array
    {
        if ($recorded === null) {
            if ($eligible) {
                return $rule['outcome'] === self::OUTCOME_SKIPPED ? [] : [self::ISSUE_DECISION_MISSING];
            }

            return $baseline['reference_date'] === null ? [self::ISSUE_BASELINE_WITHOUT_TRAIL] : [];
        }

        $issues = [];

        if (count($trails) > 1) {
            $issues[] = self::ISSUE_DUPLICATE_TRAIL;
        }

        if (! $this->trailIsCoherent($trails[0], $recorded)) {
            $issues[] = self::ISSUE_TRAIL_INCONSISTENT;
        }

        if (! $this->baselineMatchesTrail($baseline, $recorded)) {
            $issues[] = self::ISSUE_BASELINE_DIFFERS_FROM_TRAIL;
        }

        return $issues;
    }

    /**
     * A trilha como uma execução da migração a grava: na categoria protegida,
     * com descrição e motivo que combinam, e com a medição que provou a cópia
     * -- só na cópia.
     *
     * @param  RecordedDecision  $recorded
     */
    private function trailIsCoherent(object $trail, array $recorded): bool
    {
        return $trail->log_name === self::PROTECTED_LOG
            && $recorded['classification'] !== null
            && ($recorded['classification'] === Classification::SafeBackfilled) === ($recorded['proven_by_measurement_id'] !== null);
    }

    /**
     * A migração grava no plano o "Realiz. inicial" da 1ª linha quando copia e o
     * deixa em 0,00% quando não copia; a data, nunca. Depois disso a aplicação
     * não altera o avanço inicial -- o MeasurementPlanSet o trava --, e qualquer
     * diferença é escrita fora do domínio.
     *
     * Dependência declarada: quando existir o fluxo de correção do avanço
     * inicial (Fase 2), que o model anuncia como "próprio e auditado", esta
     * conferência precisa aceitar o avanço sustentado pela activity de correção
     * posterior no log `measurements`. Sem isso, todo plano corrigido por ele --
     * justamente os que este relatório manda para decisão do dono -- sai como
     * divergência para sempre, e o código de saída deixa de separar o erro real
     * do corrigido.
     *
     * @param  Baseline  $baseline
     * @param  RecordedDecision  $recorded
     */
    private function baselineMatchesTrail(array $baseline, array $recorded): bool
    {
        $expected = $recorded['description'] === Classification::TRAIL_BACKFILLED
            ? $this->decimal($recorded['line_initial_percent'])
            : '0.00';

        return $baseline['reference_date'] === null && bccomp($baseline['percent'], $expected, 2) === 0;
    }

    /**
     * @return RecordedDecision
     */
    private function recordedDecision(object $trail): array
    {
        $properties = json_decode((string) $trail->properties, true);
        $properties = is_array($properties) ? $properties : [];

        return [
            'activity_id' => (int) $trail->id,
            'description' => (string) $trail->description,
            'classification' => Classification::fromTrail((string) $trail->description, $properties['reason'] ?? null),
            'reason' => $properties['reason'] ?? null,
            'plan_line_id' => $this->integerOrNull($properties['plan_line_id'] ?? null),
            'sequence_number' => $this->integerOrNull($properties['sequence_number'] ?? null),
            'line_initial_percent' => $properties['line_initial_percent'] ?? null,
            'measured_percent' => $properties['measured_percent'] ?? null,
            'proven_by_measurement_id' => $this->integerOrNull($properties['proven_by_measurement_id'] ?? null),
            'contradicted_by_measurement_id' => $this->integerOrNull($properties['contradicted_by_measurement_id'] ?? null),
            'decided_at' => (string) $trail->created_at,
        ];
    }

    /**
     * As aprovações que já valiam quando a decisão foi gravada: análise sem data
     * (como nas mais antigas) ou até o mesmo segundo dela. Sem isso, uma
     * aprovação nova num plano não copiado -- que parte de base 0 -- pareceria
     * desmentir a decisão. A precisão é de segundos, e o empate entra: a
     * reavaliação é informativa, e na dúvida mostra a mudança.
     *
     * @param  list<ApprovedEntry>  $entries
     * @return list<ApprovedEntry>
     */
    private function entriesApprovedUntil(array $entries, string $decidedAt): array
    {
        $decision = CarbonImmutable::parse($decidedAt);

        return array_values(array_filter(
            $entries,
            fn (array $entry): bool => blank($entry['reviewed_at'] ?? null)
                || CarbonImmutable::parse((string) $entry['reviewed_at'])->lessThanOrEqualTo($decision),
        ));
    }

    /**
     * A regra, refeita com a 1ª linha de hoje e só com as aprovações que já
     * valiam na decisão, daria outra classificação, outra linha ou outro inicial.
     * A decisão gravada continua valendo; o relatório só avisa.
     *
     * @param  Decision  $reevaluated
     * @param  RecordedDecision  $recorded
     */
    private function drifted(array $reevaluated, array $recorded): bool
    {
        return $reevaluated['classification'] !== $recorded['classification']
            || $reevaluated['plan_line_id'] !== $recorded['plan_line_id']
            || bccomp($this->decimal($reevaluated['line_initial_percent']), $this->decimal($recorded['line_initial_percent']), 2) !== 0;
    }

    /**
     * Aprovações com mensal ou acumulado em texto que não é número ('n/d',
     * vazio): a migração os lê como 0, o que desloca a base e pode decidir o
     * plano. O valor não escalar fica de fora -- nele a migração não lê 0,
     * quebra ({@see self::unconvertibleMeasurementIds()}).
     *
     * @param  list<ApprovedEntry>  $entries
     * @return list<int>
     */
    private function unreadableMeasurementIds(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            if ($this->isUnreadableText($entry['monthly']) || $this->isUnreadableText($entry['cumulative'])) {
                $ids[$entry['measurement_id']] = $entry['measurement_id'];
            }
        }

        return array_values($ids);
    }

    /**
     * Aprovações com mensal ou acumulado que nem vira texto: a conversão da
     * migração quebra nelas. Nulo não entra -- vira texto vazio, lido como 0.
     *
     * @param  list<ApprovedEntry>  $entries
     * @return list<int>
     */
    private function unconvertibleMeasurementIds(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            if (! $this->isConvertibleToText($entry['monthly']) || ! $this->isConvertibleToText($entry['cumulative'])) {
                $ids[$entry['measurement_id']] = $entry['measurement_id'];
            }
        }

        return array_values($ids);
    }

    /**
     * @param  array<int, PlanReport>  $plans
     * @return array<string, int>
     */
    private function summary(array $plans): array
    {
        $summary = [];

        foreach (Classification::cases() as $classification) {
            $summary[$classification->value] = 0;
        }

        foreach ($plans as $plan) {
            $summary[$plan['classification']->value]++;
        }

        return $summary;
    }

    /**
     * @param  ApprovedEntry  $entry
     * @return array{measurement_id: int, monthly: string, base: string}
     */
    private function contribution(array $entry): array
    {
        $monthly = $this->decimal($entry['monthly']);

        return [
            'measurement_id' => $entry['measurement_id'],
            'monthly' => $monthly,
            'base' => bcsub($this->decimal($entry['cumulative']), $monthly, 2),
        ];
    }

    /**
     * @return LineSummary
     */
    private function lineSummary(object $line): array
    {
        $initial = $line->initial_realized_cumulative_percent;

        return [
            'id' => (int) $line->id,
            'sequence_number' => (int) $line->sequence_number,
            'initial_percent' => $this->isReadableDecimal($initial) ? $this->decimal($initial) : $this->text($initial),
        ];
    }

    /**
     * Número como string de duas casas, sem passar por float; '0.00' para vazio
     * ou formato inesperado -- a leitura da migração. Um valor não escalar, que
     * nela quebra a conversão para texto, vale '0.00' aqui só para a conta
     * seguir; a quebra é apontada em `migration_would_fail_measurement_ids`.
     */
    private function decimal(mixed $value): string
    {
        return $this->isReadableDecimal($value) ? bcadd(trim((string) $value), '0', 2) : '0.00';
    }

    /**
     * Percentual em (0, 100] como string de duas casas, sem passar por float;
     * `null` para zero, vazio, negativo, acima de 100 ou formato inesperado --
     * a leitura da migração.
     */
    private function percentWithinRange(mixed $value): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        $percent = bcadd($value, '0', 2);

        return bccomp($percent, '0', 2) > 0 && bccomp($percent, '100', 2) <= 0 ? $percent : null;
    }

    private function isReadableDecimal(mixed $value): bool
    {
        return $this->isConvertibleToText($value)
            && preg_match('/^-?\d+(\.\d+)?$/', trim((string) $value)) === 1;
    }

    /**
     * O que a conversão `(string)` da migração aceita sem quebrar.
     */
    private function isConvertibleToText(mixed $value): bool
    {
        return is_scalar($value) || $value === null;
    }

    private function isUnreadableText(mixed $value): bool
    {
        return $this->isConvertibleToText($value) && ! $this->isReadableDecimal($value);
    }

    /**
     * "Realiz. inicial" ausente: vazio, ou zero depois do truncamento a duas
     * casas que a migração faz.
     */
    private function isZeroOrBlank(mixed $value): bool
    {
        if ($value === null || (is_scalar($value) && trim((string) $value) === '')) {
            return true;
        }

        return $this->isReadableDecimal($value) && bccomp($this->decimal($value), '0', 2) === 0;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : null;
    }

    private function dateOf(mixed $value): ?string
    {
        return blank($value) ? null : substr((string) $value, 0, 10);
    }

    private function text(mixed $value): string
    {
        return $value === null || is_scalar($value) ? trim((string) $value) : get_debug_type($value);
    }
}
