<?php

namespace Tests\Support;

use App\Enums\MeasurementInitialProgressClassification as Classification;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Services\MeasurementEngineeringService;
use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Planos como estavam antes de o avanço físico inicial morar no plano -- o
 * "Realiz. inicial" digitado linha a linha -- e aprovações da Engenharia
 * gravadas pelo cálculo antigo, para exercitar a migração 2026_10_05_170828 e
 * o relatório `measurements:initial-progress-report`, que prevê e confere a
 * decisão dela.
 *
 * É classe, e não função de arquivo de teste, porque três arquivos montam os
 * mesmos planos: duas funções globais com o mesmo nome em arquivos Pest dão
 * "Cannot redeclare", e a matriz de cenários precisa ser a mesma no teste de
 * paridade (SQLite e MySQL) e no grupo `mysql`.
 */
final class MeasurementLegacyInitialProgressFixture
{
    public const MIGRATION = '2026_10_05_170828_add_initial_physical_progress_to_measurement_plan_sets_table';

    /**
     * As propriedades da trilha que descrevem a decisão. O relatório as prevê
     * com os mesmos nomes, e a paridade as compara uma a uma, com tipo.
     *
     * @var list<string>
     */
    public const DECISION_PROPERTIES = [
        'reason',
        'plan_line_id',
        'sequence_number',
        'line_initial_percent',
        'measured_percent',
        'proven_by_measurement_id',
        'contradicted_by_measurement_id',
    ];

    /**
     * A migração real, tal como foi publicada; cada chamada devolve uma
     * instância nova para rodar up() ou down().
     */
    public static function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /**
     * Plano como estava antes da coluna nova: sem avanço inicial, com o
     * "Realiz. inicial" digitado linha a linha.
     *
     * @param  array<int, int|float|string>  $initialsBySequence
     * @return array{planSet: MeasurementPlanSet, lines: array<int, MeasurementPlanLine>}
     */
    public static function planWithLegacyLineInitials(array $initialsBySequence, ?Operation $operation = null): array
    {
        $planSet = MeasurementPlanSet::factory()->create($operation === null ? [] : ['operation_id' => $operation->id]);
        $lines = [];

        foreach ($initialsBySequence as $sequence => $initial) {
            $lines[$sequence] = MeasurementPlanLine::factory()->create([
                'plan_set_id' => $planSet->id,
                'operation_id' => $planSet->operation_id,
                'sequence_number' => $sequence,
                'measurement_date' => sprintf('2026-%02d-01', 4 + $sequence),
                'initial_realized_cumulative_percent' => $initial,
            ]);
        }

        return compact('planSet', 'lines');
    }

    /**
     * Aprovação gravada pelo cálculo antigo: o snapshot guarda o inicial da linha,
     * o mensal e o acumulado que o motor calculou.
     */
    public static function legacyApproval(MeasurementPlanLine $line, string $initial, string $monthly, string $cumulative, string $engineering = 'approved'): Measurement
    {
        $measurement = self::legacyMeasurement($line, $initial, $monthly, $cumulative);
        $measurement->reviews()->create(['stage' => 1, 'status' => $engineering]);

        return $measurement;
    }

    /**
     * Aprovação cujo snapshot guarda o mensal como lista, e não como número. A
     * migração converte cada valor em texto e quebra nele ("Array to string
     * conversion"). O motor nunca gravou esse formato, mas um snapshot assim em
     * produção pararia o deploy no `migrate` -- é o caso que o relatório precisa
     * apontar antes. O snapshot é regravado antes de a análise existir: depois
     * da aprovação o model o bloqueia.
     */
    public static function legacyApprovalWithUnconvertibleProgress(MeasurementPlanLine $line, string $initial, string $monthly, string $cumulative): Measurement
    {
        $measurement = self::legacyMeasurement($line, $initial, $monthly, $cumulative);
        $snapshot = $measurement->engineering_snapshot;
        $snapshot['plan_sets'][0]['realized_monthly_percent'] = [$monthly];

        DB::table('measurements')->where('id', $measurement->id)->update([
            'engineering_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
        $measurement->reviews()->create(['stage' => 1, 'status' => 'approved']);

        return $measurement;
    }

    /**
     * Aprovação anterior ao snapshot da Engenharia: o avanço aprovado ficou só na
     * linha que a medição gravou.
     */
    public static function legacyApprovalWithoutSnapshot(MeasurementPlanLine $line, string $monthly, string $cumulative): Measurement
    {
        return self::legacyApprovalWithoutSnapshotOnLines([[$line, $monthly, $cumulative]]);
    }

    /**
     * Uma única aprovação anterior ao snapshot que gravou linhas de mais de um
     * plano da operação. Sem snapshot, a linha é o único registro do avanço
     * aprovado, e cada plano só pode ler as próprias: a medição é da operação,
     * não do plano.
     *
     * @param  non-empty-list<array{0: MeasurementPlanLine, 1: string, 2: string}>  $progressByLine  linha, mensal e acumulado; a 1ª dá a operação e a competência
     */
    public static function legacyApprovalWithoutSnapshotOnLines(array $progressByLine): Measurement
    {
        [$firstLine] = $progressByLine[0];

        $measurement = Measurement::factory()->create([
            'operation_id' => $firstLine->operation_id,
            'reference_month' => $firstLine->measurement_date->toDateString(),
            'status' => 'in_review',
            'current_stage' => 2,
            'engineering_snapshot' => null,
        ]);
        $measurement->reviews()->create(['stage' => 1, 'status' => 'approved']);

        foreach ($progressByLine as [$line, $monthly, $cumulative]) {
            DB::table('measurement_plan_lines')->where('id', $line->id)->update([
                'measurement_id' => $measurement->id,
                'realized_monthly_percent' => $monthly,
                'realized_cumulative_percent' => $cumulative,
            ]);
        }

        return $measurement;
    }

    /**
     * Os nomes da matriz de cenários, na ordem em que o teste de paridade os
     * percorre.
     *
     * @return list<string>
     */
    public static function scenarioNames(): array
    {
        return array_keys(self::scenarios());
    }

    /**
     * Monta um cenário da matriz e devolve a classificação que a migração dá a
     * cada plano dele, pelo id do plano.
     *
     * @return array<int, Classification>
     */
    public static function scenario(string $name): array
    {
        $arrange = self::scenarios()[$name] ?? throw new InvalidArgumentException("Cenário desconhecido: {$name}.");

        return $arrange();
    }

    /**
     * As linhas da trilha de decisão do plano, na ordem em que foram gravadas.
     * Busca pelo plano, nunca global: no grupo `mysql` outras linhas commitadas
     * podem estar no banco.
     *
     * @return Collection<int, object>
     */
    public static function trailsOf(int $planSetId): Collection
    {
        return DB::table('activity_log')
            ->where('subject_type', 'App\Models\MeasurementPlanSet')
            ->where('subject_id', $planSetId)
            ->whereIn('description', [Classification::TRAIL_BACKFILLED, Classification::TRAIL_NOT_BACKFILLED])
            ->orderBy('id')
            ->get();
    }

    /**
     * As propriedades da decisão na ordem de {@see self::DECISION_PROPERTIES}.
     * O MySQL reordena as chaves de um JSON, e a comparação precisa ser estrita,
     * com tipo -- é ela que distingue o '0' que a migração grava sem aprovação do
     * '0.00' --, o que exige a mesma ordem dos dois lados.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function decisionOf(array $source): array
    {
        $decision = [];

        foreach (self::DECISION_PROPERTIES as $property) {
            $decision[$property] = array_key_exists($property, $source) ? $source[$property] : '(ausente)';
        }

        return $decision;
    }

    /**
     * Roda o relatório em JSON e devolve o código de saída e o conteúdo, com os
     * planos indexados pelo id.
     *
     * @param  array<string, mixed>  $options
     * @return array{exit_code: int, payload: array<string, mixed>, plans: array<int, array<string, mixed>>}
     */
    public static function jsonReport(array $options = []): array
    {
        $exitCode = Artisan::call('measurements:initial-progress-report', ['--json' => true] + $options);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return [
            'exit_code' => $exitCode,
            'payload' => $payload,
            'plans' => collect($payload['plans'])->keyBy('plan_set_id')->all(),
        ];
    }

    /**
     * Roda o relatório em texto e devolve o código de saída e a saída inteira.
     *
     * Para conferir vários trechos da mesma linha da tabela: o
     * `expectsOutputToContain` entrega cada linha escrita à primeira expectativa
     * que casa, e a segunda expectativa da mesma linha nunca é satisfeita.
     *
     * @param  array<string, mixed>  $options
     * @return array{exit_code: int, output: string}
     */
    public static function textReport(array $options = []): array
    {
        $exitCode = Artisan::call('measurements:initial-progress-report', $options);

        return ['exit_code' => $exitCode, 'output' => Artisan::output()];
    }

    /**
     * @return array<string, Closure(): array<int, Classification>>
     */
    private static function scenarios(): array
    {
        return [
            'proven on the first line' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0]);
                self::legacyApproval($lines[1], '35.00', '5.00', '40.00');

                return [$planSet->id => Classification::SafeBackfilled];
            },
            'proven after the first line' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
                self::legacyApproval($lines[1], '35.00', '5.00', '40.00');
                self::legacyApproval($lines[2], '0.00', '4.00', '44.00');

                return [$planSet->id => Classification::SafeBackfilled];
            },
            'initial plus measured reaching exactly 100%' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
                self::legacyApproval($lines[1], '35.00', '5.00', '40.00');
                self::legacyApproval($lines[2], '0.00', '60.00', '100.00');

                return [$planSet->id => Classification::SafeBackfilled];
            },
            'legacy initial of exactly 100%' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 100, 2 => 0]);
                self::legacyApproval($lines[1], '100.00', '0.00', '100.00');

                return [$planSet->id => Classification::SafeBackfilled];
            },
            'approved before the engineering snapshot existed' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0]);
                self::legacyApprovalWithoutSnapshot($lines[1], '5.00', '40.00');

                return [$planSet->id => Classification::SafeBackfilled];
            },
            'negative monthly progress summed' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
                self::legacyApproval($lines[1], '35.00', '5.00', '40.00');
                self::legacyApproval($lines[2], '0.00', '-2.00', '38.00');

                return [$planSet->id => Classification::SafeBackfilled];
            },
            'not measured yet' => function (): array {
                ['planSet' => $planSet] = self::planWithLegacyLineInitials([1 => 35, 2 => 0]);

                return [$planSet->id => Classification::DeclaredInitialWithNoCurrentApproval];
            },
            'first approval returned to Engineering' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0]);
                self::legacyApproval($lines[1], '35.00', '5.00', '40.00', engineering: 'pending');

                return [$planSet->id => Classification::DeclaredInitialWithNoCurrentApproval];
            },
            'a later line approved from a zero base' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0]);
                self::legacyApproval($lines[2], '0.00', '10.00', '10.00');

                return [$planSet->id => Classification::ApprovalStartedBelowDeclaredInitial];
            },
            'contradiction prevails over going above 100%' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0]);
                self::legacyApproval($lines[2], '0.00', '70.00', '70.00');

                return [$planSet->id => Classification::ApprovalStartedBelowDeclaredInitial];
            },
            'unreadable snapshot entry read as zero' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0]);
                self::legacyApproval($lines[1], '35.00', '5.00', 'n/d');

                return [$planSet->id => Classification::ApprovalStartedBelowDeclaredInitial];
            },
            'contradicted by the lowest measurement id even when reviewed later' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
                $first = self::legacyMeasurement($lines[2], '0.00', '10.00', '10.00');
                self::legacyApproval($lines[3], '0.00', '10.00', '20.00');
                $first->reviews()->create(['stage' => 1, 'status' => 'approved']);

                return [$planSet->id => Classification::ApprovalStartedBelowDeclaredInitial];
            },
            'copy would take the plan just above 100%' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
                self::legacyApproval($lines[1], '35.00', '5.00', '40.00');
                self::legacyApproval($lines[2], '0.00', '60.01', '100.01');

                return [$planSet->id => Classification::InitialPlusMeasuredAbove100];
            },
            'an approval before the snapshot takes it above 100%' => function (): array {
                ['planSet' => $planSet, 'lines' => $lines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
                self::legacyApproval($lines[1], '35.00', '5.00', '40.00');
                self::legacyApprovalWithoutSnapshot($lines[2], '61.00', '101.00');

                return [$planSet->id => Classification::InitialPlusMeasuredAbove100];
            },
            'two plans in the same operation' => function (): array {
                ['planSet' => $proven, 'lines' => $provenLines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0]);
                ['planSet' => $unproven] = self::planWithLegacyLineInitials([1 => 20, 2 => 0], $proven->operation);
                self::legacyApproval($provenLines[1], '35.00', '5.00', '40.00');

                return [
                    $proven->id => Classification::SafeBackfilled,
                    $unproven->id => Classification::DeclaredInitialWithNoCurrentApproval,
                ];
            },
            // Sem snapshot, as linhas da medição são lidas por plano: se as do
            // outro plano entrassem, a base 0 da linha 2 de B desmentiria A, e
            // B somaria o mensal de A.
            'one approval before the snapshot on two plans of the same operation' => function (): array {
                ['planSet' => $proven, 'lines' => $provenLines] = self::planWithLegacyLineInitials([1 => 35, 2 => 0]);
                ['planSet' => $contradicted, 'lines' => $contradictedLines] = self::planWithLegacyLineInitials([1 => 20, 2 => 0], $proven->operation);
                self::legacyApprovalWithoutSnapshotOnLines([
                    [$provenLines[1], '5.00', '40.00'],
                    [$contradictedLines[2], '3.00', '3.00'],
                ]);

                return [
                    $proven->id => Classification::SafeBackfilled,
                    $contradicted->id => Classification::ApprovalStartedBelowDeclaredInitial,
                ];
            },
            'legacy initial outside 0% to 100%' => function (): array {
                ['planSet' => $above, 'lines' => $aboveLines] = self::planWithLegacyLineInitials([1 => 100.01, 2 => 0]);
                ['planSet' => $negative] = self::planWithLegacyLineInitials([1 => -5, 2 => 0]);
                self::legacyApproval($aboveLines[1], '100.01', '0.00', '100.01');

                return [
                    $above->id => Classification::LegacyInitialOutOfRange,
                    $negative->id => Classification::LegacyInitialOutOfRange,
                ];
            },
            'no legacy initial on the first line' => function (): array {
                ['planSet' => $laterLine] = self::planWithLegacyLineInitials([1 => 0, 2 => 20]);
                ['planSet' => $withoutLines] = self::planWithLegacyLineInitials([]);

                return [
                    $laterLine->id => Classification::NoLegacyInitialProgress,
                    $withoutLines->id => Classification::NoLegacyInitialProgress,
                ];
            },
            'initial progress informed at creation' => function (): array {
                $informed = MeasurementPlanSet::factory()->withInitialPhysicalProgress('10.00', '2026-04-30')->create();
                $line = MeasurementPlanLine::factory()->create([
                    'plan_set_id' => $informed->id,
                    'operation_id' => $informed->operation_id,
                    'sequence_number' => 1,
                    'measurement_date' => '2026-05-01',
                    'initial_realized_cumulative_percent' => 35,
                ]);
                self::legacyApproval($line, '35.00', '5.00', '40.00');

                return [$informed->id => Classification::InitialInformedAtCreation];
            },
        ];
    }

    /**
     * Medição com o snapshot do cálculo antigo e ainda sem a análise da
     * Engenharia -- para que a aprovação seja criada depois, fora da ordem dos
     * ids.
     */
    private static function legacyMeasurement(MeasurementPlanLine $line, string $initial, string $monthly, string $cumulative): Measurement
    {
        return Measurement::factory()->create([
            'operation_id' => $line->operation_id,
            'reference_month' => $line->measurement_date->toDateString(),
            'status' => 'in_review',
            'current_stage' => 2,
            'engineering_snapshot' => [
                'schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION,
                'plan_sets' => [[
                    'plan_set_id' => $line->plan_set_id,
                    'plan_line_id' => $line->id,
                    'sequence_number' => $line->sequence_number,
                    'measurement_date' => $line->measurement_date->toDateString(),
                    'initial_realized_cumulative_percent' => $initial,
                    'realized_monthly_percent' => $monthly,
                    'realized_cumulative_percent' => $cumulative,
                ]],
            ],
        ]);
    }
}
