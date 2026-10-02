<?php

declare(strict_types=1);

namespace App\Services\Guarantees;

use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Models\Emission;
use App\Models\GuaranteeSnapshot;
use App\Models\User;
use App\Support\Money\IntegerMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marca como desatualizadas pelo saldo devedor as competências de garantias
 * cujo saldo gravado deixou de ser o que a fonte de PU responde hoje.
 *
 * O saldo devedor de uma competência vem do {@see OutstandingBalanceResolver}:
 * PU do mês (curva oficial homologada ou, sem ela, Histórico de PU) vezes a
 * quantidade integralizada. Homologar ou invalidar uma curva, importar o
 * Histórico, estender a curva oficial, a projeção legada e a integralização
 * mudam esse número -- e o snapshot, fechado ou não, continuaria mostrando o
 * antigo sem nada avisar. A marca é o aviso; o número só muda por ação humana
 * (atualizar a competência aberta ou reabrir a fechada).
 *
 * A decisão é por comparação exata em centavos entre o saldo gravado e o
 * apurado agora: uma troca de fonte que não muda o saldo daquele mês não marca
 * nada, e não há tolerância que esconda um centavo.
 *
 * Travas na mesma ordem do {@see GuaranteeSnapshotSalesBoardInvalidator}: a
 * emissão em modo compartilhado -- o {@see GuaranteeSnapshotWriter} a trava em
 * modo exclusivo antes de apurar, então uma gravação em andamento termina antes
 * da comparação --, depois os snapshots com `FOR UPDATE`, na ordem da
 * competência.
 *
 * O {@see EmissionPuReader} memoriza a versão oficial da curva por instância.
 * Um serviço resolvido antes de uma homologação, no mesmo processo, compararia
 * com a curva antiga. Por isso ele é resolvido de novo a cada evento (o ouvinte
 * nasce do container a cada disparo) e a cada execução do comando.
 */
class GuaranteeSnapshotOutstandingBalanceInvalidator
{
    public const SOURCE = 'outstanding_balance';

    public function __construct(
        private readonly OutstandingBalanceResolver $outstandingBalances,
    ) {}

    /**
     * Marca as competências cujo saldo devedor mudou e registra cada marca na
     * trilha protegida.
     *
     * @param  string  $reason  o que mudou a fonte, gravado na linha e na trilha
     * @param  bool  $onlyEndedCompetences  só competências já encerradas no calendário de negócio
     * @return int quantidade de competências marcadas
     */
    public function markDrifted(
        int $emissionId,
        string $reason,
        bool $onlyEndedCompetences = false,
        ?int $causerId = null,
    ): int {
        $reason = mb_substr(trim($reason), 0, 255);
        $causer = $causerId === null ? null : User::query()->find($causerId);

        return DB::transaction(function () use ($emissionId, $reason, $onlyEndedCompetences, $causer): int {
            $emission = Emission::query()->whereKey($emissionId)->sharedLock()->first();

            if (! $emission instanceof Emission) {
                return 0;
            }

            $marked = 0;

            foreach ($this->candidates($emission, $onlyEndedCompetences, lock: true) as $snapshot) {
                $current = $this->currentOutstandingBalance($emission, $snapshot);

                if (! $this->differs($snapshot, $current)) {
                    continue;
                }

                $recorded = $snapshot->outstanding_balance;

                $snapshot->forceFill([
                    'outstanding_balance_outdated_at' => now(),
                    'outstanding_balance_outdated_reason' => $reason,
                ])->save();

                activity(GuaranteeSnapshotWriter::LOG_NAME)
                    ->causedBy($causer)
                    ->performedOn($snapshot)
                    ->event(GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)
                    ->withProperties([
                        'emission_id' => $emissionId,
                        'reference_month' => $snapshot->reference_month->toDateString(),
                        'source' => self::SOURCE,
                        'reason' => $reason,
                        'recorded_outstanding_balance' => $recorded === null ? null : (string) $recorded,
                        'current_outstanding_balance' => $current === null ? null : IntegerMoney::decimalString((int) IntegerMoney::cents($current)),
                        'closed' => $snapshot->isClosed(),
                    ])
                    ->log('Competência de garantias desatualizada pelo saldo devedor');

                $marked++;
            }

            return $marked;
        });
    }

    /**
     * O que {@see self::markDrifted()} marcaria, sem gravar nada.
     *
     * @return list<array{snapshot: GuaranteeSnapshot, recorded: string|null, current: float|null}>
     */
    public function drifted(int $emissionId, bool $onlyEndedCompetences = false): array
    {
        $emission = Emission::query()->find($emissionId);

        if (! $emission instanceof Emission) {
            return [];
        }

        $drifted = [];

        foreach ($this->candidates($emission, $onlyEndedCompetences, lock: false) as $snapshot) {
            $current = $this->currentOutstandingBalance($emission, $snapshot);

            if (! $this->differs($snapshot, $current)) {
                continue;
            }

            $drifted[] = [
                'snapshot' => $snapshot,
                'recorded' => $snapshot->outstanding_balance === null ? null : (string) $snapshot->outstanding_balance,
                'current' => $current,
            ];
        }

        return $drifted;
    }

    /**
     * Snapshots da emissão ainda sem a marca, na ordem da competência.
     *
     * A competência em curso fica fora quando pedido: o saldo dela muda a cada
     * dia novo de PU, e marcá-la todo dia só ensinaria a ignorar a marca.
     *
     * @return Collection<int, GuaranteeSnapshot>
     */
    private function candidates(Emission $emission, bool $onlyEndedCompetences, bool $lock): Collection
    {
        return GuaranteeSnapshot::query()
            ->where('emission_id', $emission->getKey())
            ->whereNull('outstanding_balance_outdated_at')
            ->when(
                $onlyEndedCompetences,
                fn (Builder $query): Builder => $query->whereDate('reference_month', '<', GuaranteeSnapshot::currentBusinessMonth()),
            )
            ->orderBy('reference_month')
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get();
    }

    private function currentOutstandingBalance(Emission $emission, GuaranteeSnapshot $snapshot): ?float
    {
        return $this->outstandingBalances->resolveOrNull($emission, $snapshot->reference_month->toDateString());
    }

    /**
     * Diferença de pelo menos um centavo -- inclusive entre ter e não ter saldo.
     */
    private function differs(GuaranteeSnapshot $snapshot, ?float $current): bool
    {
        return IntegerMoney::cents($snapshot->outstanding_balance) !== IntegerMoney::cents($current);
    }
}
