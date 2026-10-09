<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuObligationRefreshResult;
use App\Domain\PuCalculator\Services\PuFinancialObligationService;
use App\Domain\PuCalculator\Services\PuObligationRefreshRecovery;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuObligation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Refaz as obrigações financeiras do PU e a conciliação a partir dos fatos: o
 * cálculo esperado da curva oficial (imutável), o cronograma informado e o livro
 * de liquidações. Nenhum fato muda -- nem curva, nem liquidação; só o que é
 * derivado é recomposto.
 *
 * Com `--verify`, faz a mesma conta dentro de uma transação desfeita no fim e
 * lista as emissões cujo estado gravado diverge do que os fatos dizem (sai com
 * falha se houver alguma).
 *
 * Fase 6: a recomposição passa pela atualização durável
 * ({@see PuObligationRefreshRecovery}) -- fica registrada como execução manual,
 * encerra os pedidos abertos da emissão que ela atende (inclusive os esgotados)
 * e, se falhar, deixa a falha classificada no pedido em vez de derrubar o
 * comando no meio das emissões.
 */
class ReconcilePuObligationsCommand extends Command
{
    protected $signature = 'pu:obligations:reconcile
        {--emission=* : Processa apenas as emissoes informadas (ID)}
        {--verify : Recalcula sem gravar e aponta a deriva}';

    protected $description = 'Refaz as obrigacoes financeiras do PU e a conciliacao com as liquidacoes a partir dos fatos (curva oficial, cronograma informado, livro de liquidacoes), sem alterar nenhum deles.';

    public function handle(PuFinancialObligationService $obligations, PuObligationRefreshRecovery $recovery): int
    {
        $verify = (bool) $this->option('verify');
        $rows = [];
        $drift = 0;
        $failed = 0;

        foreach ($this->emissionIds() as $emissionId) {
            $emission = Emission::query()->find($emissionId);

            if (! $emission instanceof Emission) {
                continue;
            }

            if ($verify) {
                DB::beginTransaction();

                try {
                    $result = $obligations->refresh($emission, 'verification');
                } finally {
                    DB::rollBack();
                }
            } else {
                $recovery->request($emission->id, 'manual_rebuild');
                $outcome = $recovery->process($emission->id, PuObligationRefreshRecovery::VIA_MANUAL);

                if (! $outcome->succeeded() || ! $outcome->result instanceof PuObligationRefreshResult) {
                    $failed++;
                    $rows[] = [$emission->id, '—', '—', '—', '—', '—', sprintf('falhou: %s', $outcome->failureCategory?->label() ?? $outcome->status)];

                    continue;
                }

                $result = $outcome->result;
            }

            $drift += $result->changedAnything() ? 1 : 0;
            $rows[] = [
                $emission->id,
                $result->calculationVersion ?? '—',
                $result->count('created'),
                $result->count('calculations_added'),
                $result->count('superseded'),
                $result->count('reconciliations_changed'),
                $result->changedAnything() ? ($verify ? 'deriva' : 'atualizada') : 'em dia',
            ];
        }

        $this->table(['Emissão', 'Curva oficial', 'Obrigações novas', 'Cálculos novos', 'Superadas', 'Conciliações alteradas', 'Situação'], $rows);

        if ($failed > 0) {
            $this->error(sprintf('%d emissão(ões) não puderam ser recompostas; o pedido ficou registrado com a falha (pu:obligations:recover --list).', $failed));

            return self::FAILURE;
        }

        if ($verify && $drift > 0) {
            $this->error(sprintf('%d emissão(ões) com estado derivado diferente do que os fatos dizem.', $drift));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function emissionIds(): array
    {
        $requested = array_values(array_filter(array_map('intval', (array) $this->option('emission'))));

        if ($requested !== []) {
            return $requested;
        }

        return EmissionPuCurveVersion::query()->official()->pluck('emission_id')
            ->merge(EmissionPuObligation::query()->distinct()->pluck('emission_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
