<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avanço físico inicial do plano de medição: quanto da obra já estava executado
 * antes de o acompanhamento começar no sistema, e a data a que esse percentual
 * se refere.
 *
 * Fica no plano -- o contexto de medição de uma obra dentro da operação, a
 * mesma unidade em que o acumulado e o teto de 100% sempre foram calculados --,
 * e não na medição nem nas linhas do cronograma.
 *
 * Dados existentes: tudo é aditivo e nada é sobrescrito. Todo plano nasce com
 * 0,00% e sem data. O avanço inicial antigo era o "Realiz. inicial" digitado
 * na linha, e o cálculo antigo só o usava na primeira linha do cronograma. Ele
 * é copiado para o plano só quando o histórico aprovado prova que estava em
 * vigor: há aprovação vigente da Engenharia no plano, nenhuma partiu de base
 * menor que ele (o mês pulado do P1-03 o desmentiria) e o inicial mais o que
 * as medições vigentes somam não passa de 100%. Aí ele já está dentro dos
 * números aprovados, e copiá-lo os preserva. Valor que nenhuma aprovação usou
 * não vira avanço inicial imutável por suposição: o plano fica em 0,00% e a
 * decisão é do dono. A data de referência não é inventada. Cada plano com
 * "Realiz. inicial" na primeira linha deixa uma activity em `measurements` --
 * copiado, com a linha e a medição que o provaram, ou não copiado, com o
 * motivo.
 *
 * Cada plano é decidido e gravado numa transação que trava a Operation
 * primeiro, como a aprovação da Engenharia: a aplicação atende durante o
 * migrate do deploy, e uma aprovação no meio não pode escapar da conta.
 *
 * No MySQL um CHECK recusa percentual fora de 0..100 mesmo numa escrita que não
 * passe pela aplicação. Derrube o CHECK antes de renomear ou remover a coluna.
 * O SQLite dos testes não ganha a regra: ele não aceita ADD CONSTRAINT, e a
 * reconstrução de tabela do Laravel a descartaria em silêncio. A data
 * obrigatória quando o percentual é positivo fica só no domínio, porque os
 * planos copiados acima têm percentual sem data.
 *
 * Idempotente: pode rodar de novo sem duplicar coluna, cópia ou restrição.
 */
return new class extends Migration
{
    private const CHECK_NAME = 'measurement_plan_sets_initial_physical_progress_check';

    private const CHUNK_SIZE = 200;

    public function up(): void
    {
        if (! Schema::hasColumn('measurement_plan_sets', 'initial_physical_progress_percent')) {
            Schema::table('measurement_plan_sets', function (Blueprint $table): void {
                $table->decimal('initial_physical_progress_percent', 5, 2)->default(0)->after('initial_incurred_amount');
            });
        }

        if (! Schema::hasColumn('measurement_plan_sets', 'initial_physical_progress_reference_date')) {
            Schema::table('measurement_plan_sets', function (Blueprint $table): void {
                $table->date('initial_physical_progress_reference_date')->nullable()->after('initial_physical_progress_percent');
            });
        }

        $this->copyInitialProgressProvenByTheApprovedHistory();

        if (DB::getDriverName() === 'mysql' && ! $this->hasRangeCheck()) {
            $outOfRange = DB::table('measurement_plan_sets')
                ->where(fn ($query) => $query
                    ->where('initial_physical_progress_percent', '<', 0)
                    ->orWhere('initial_physical_progress_percent', '>', 100))
                ->count();

            if ($outOfRange > 0) {
                throw new RuntimeException(
                    "measurement_plan_sets possui {$outOfRange} plano(s) com avanço físico inicial fora de 0..100. "
                        .'A aplicação não grava esse estado; cada linha é um caso a investigar antes de criar a restrição.'
                );
            }

            DB::statement(sprintf(
                'ALTER TABLE measurement_plan_sets ADD CONSTRAINT %s CHECK (initial_physical_progress_percent >= 0 AND initial_physical_progress_percent <= 100)',
                self::CHECK_NAME,
            ));
        }
    }

    /**
     * Desfaz a estrutura enquanto nenhum plano tiver avanço físico inicial. Com
     * ele no banco -- informado na criação ou copiado da linha, com a activity
     * que registra a cópia --, a reversão apagaria evidência imutável e deixaria
     * a trilha apontando para um valor que não existe mais; por isso é recusada.
     * Corrija para frente.
     */
    public function down(): void
    {
        if (Schema::hasColumn('measurement_plan_sets', 'initial_physical_progress_percent')
            && DB::table('measurement_plan_sets')
                ->where(fn ($query) => $query
                    ->where('initial_physical_progress_percent', '>', 0)
                    ->orWhereNotNull('initial_physical_progress_reference_date'))
                ->exists()) {
            throw new RuntimeException(
                'measurement_plan_sets possui avanço físico inicial registrado; '
                    .'a reversão o apagaria e não é admitida. Corrija com uma nova migration.'
            );
        }

        if (DB::getDriverName() === 'mysql' && $this->hasRangeCheck()) {
            DB::statement(sprintf('ALTER TABLE measurement_plan_sets DROP CHECK %s', self::CHECK_NAME));
        }

        foreach (['initial_physical_progress_reference_date', 'initial_physical_progress_percent'] as $column) {
            if (Schema::hasColumn('measurement_plan_sets', $column)) {
                Schema::table('measurement_plan_sets', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function copyInitialProgressProvenByTheApprovedHistory(): void
    {
        DB::table('measurement_plan_sets')
            ->where('initial_physical_progress_percent', 0)
            ->whereNull('initial_physical_progress_reference_date')
            ->select('id', 'operation_id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $planSets): void {
                foreach ($planSets as $planSet) {
                    DB::transaction(fn () => $this->decideInitialProgress((int) $planSet->id, (int) $planSet->operation_id));
                }
            });
    }

    private function decideInitialProgress(int $planSetId, int $operationId): void
    {
        // Operation primeiro, como na aprovação da Engenharia: daqui em diante a
        // leitura enxerga toda aprovação commitada, e nenhuma nova entra até o fim.
        DB::table('operations')->where('id', $operationId)->lockForUpdate()->first();

        $stillUnset = DB::table('measurement_plan_sets')
            ->where('id', $planSetId)
            ->where('initial_physical_progress_percent', 0)
            ->whereNull('initial_physical_progress_reference_date')
            ->exists()
            && ! DB::table('activity_log')
                ->where('subject_type', 'App\Models\MeasurementPlanSet')
                ->where('subject_id', $planSetId)
                ->whereIn('description', ['initial_physical_progress_backfilled', 'initial_physical_progress_not_backfilled'])
                ->exists();

        $firstLine = DB::table('measurement_plan_lines')
            ->where('plan_set_id', $planSetId)
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->first(['id', 'sequence_number', 'initial_realized_cumulative_percent']);

        $initial = $this->percentWithinRange($firstLine?->initial_realized_cumulative_percent);

        if (! $stillUnset || $firstLine === null || $initial === null) {
            return;
        }

        $approved = $this->approvedContributions($planSetId, $operationId);
        $measured = array_reduce($approved, fn (string $sum, array $entry): string => bcadd($sum, $entry['monthly'], 2), '0');
        $contradicting = array_values(array_filter($approved, fn (array $entry): bool => bccomp($entry['base'], $initial, 2) < 0));

        $reason = match (true) {
            $approved === [] => 'no_current_approval',
            $contradicting !== [] => 'approval_started_below_initial',
            bccomp(bcadd($initial, $measured, 2), '100', 2) > 0 => 'initial_plus_measured_above_100',
            default => null,
        };

        if ($reason === null) {
            DB::table('measurement_plan_sets')
                ->where('id', $planSetId)
                ->update(['initial_physical_progress_percent' => $initial]);
        }

        DB::table('activity_log')->insert([
            'log_name' => 'measurements',
            'description' => $reason === null ? 'initial_physical_progress_backfilled' : 'initial_physical_progress_not_backfilled',
            'event' => $reason === null ? 'updated' : null,
            'subject_type' => 'App\Models\MeasurementPlanSet',
            'subject_id' => $planSetId,
            'attribute_changes' => $reason === null ? json_encode([
                'attributes' => ['initial_physical_progress_percent' => $initial],
                'old' => ['initial_physical_progress_percent' => '0.00'],
            ], JSON_THROW_ON_ERROR) : null,
            'properties' => json_encode([
                'source' => 'measurement_plan_lines.initial_realized_cumulative_percent',
                'migration' => '2026_10_05_170828_add_initial_physical_progress_to_measurement_plan_sets_table',
                'plan_line_id' => (int) $firstLine->id,
                'sequence_number' => (int) $firstLine->sequence_number,
                'line_initial_percent' => $initial,
                'measured_percent' => $measured,
                'proven_by_measurement_id' => $reason === null ? $approved[0]['measurement_id'] : null,
                'contradicted_by_measurement_id' => $contradicting[0]['measurement_id'] ?? null,
                'reason' => $reason,
                'reference_date' => null,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Avanço aprovado vigente do plano, com a base de que cada aprovação partiu
     * (acumulado gravado menos o mensal): do snapshot, ou, nas aprovações
     * anteriores a ele, da linha que gravaram.
     *
     * @return list<array{measurement_id: int, plan_line_id: int, monthly: string, base: string}>
     */
    private function approvedContributions(int $planSetId, int $operationId): array
    {
        $approved = DB::table('measurements')
            ->join('measurement_reviews', 'measurement_reviews.measurement_id', '=', 'measurements.id')
            ->where('measurements.operation_id', $operationId)
            ->where('measurement_reviews.stage', 1)
            ->where('measurement_reviews.status', 'approved')
            ->orderBy('measurements.id')
            ->get(['measurements.id', 'measurements.engineering_snapshot']);

        $contributions = [];

        foreach ($approved as $row) {
            if ($row->engineering_snapshot === null) {
                $lines = DB::table('measurement_plan_lines')
                    ->where('plan_set_id', $planSetId)
                    ->where('measurement_id', $row->id)
                    ->get(['id', 'realized_monthly_percent', 'realized_cumulative_percent']);

                foreach ($lines as $line) {
                    $contributions[] = $this->contribution((int) $row->id, (int) $line->id, $line->realized_monthly_percent, $line->realized_cumulative_percent);
                }

                continue;
            }

            $snapshot = json_decode((string) $row->engineering_snapshot, true);

            foreach (is_array($snapshot['plan_sets'] ?? null) ? $snapshot['plan_sets'] : [] as $entry) {
                if (is_array($entry) && (int) ($entry['plan_set_id'] ?? 0) === $planSetId) {
                    $contributions[] = $this->contribution((int) $row->id, (int) ($entry['plan_line_id'] ?? 0), $entry['realized_monthly_percent'] ?? null, $entry['realized_cumulative_percent'] ?? null);
                }
            }
        }

        return $contributions;
    }

    /**
     * @return array{measurement_id: int, plan_line_id: int, monthly: string, base: string}
     */
    private function contribution(int $measurementId, int $planLineId, mixed $monthly, mixed $cumulative): array
    {
        $monthly = $this->decimal($monthly);
        $cumulative = $this->decimal($cumulative);

        return [
            'measurement_id' => $measurementId,
            'plan_line_id' => $planLineId,
            'monthly' => $monthly,
            'base' => bcsub($cumulative, $monthly, 2),
        ];
    }

    /**
     * Valor numérico como string de duas casas, sem passar por float; 0 para
     * vazio ou formato inesperado.
     */
    private function decimal(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? bcadd($value, '0', 2) : '0.00';
    }

    /**
     * Percentual em (0, 100] como string de duas casas, sem passar por float;
     * `null` para zero, vazio, fora da faixa ou formato inesperado.
     */
    private function percentWithinRange(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        $percent = bcadd($value, '0', 2);

        return bccomp($percent, '0', 2) > 0 && bccomp($percent, '100', 2) <= 0 ? $percent : null;
    }

    private function hasRangeCheck(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'measurement_plan_sets')
            ->where('CONSTRAINT_NAME', self::CHECK_NAME)
            ->exists();
    }
};
