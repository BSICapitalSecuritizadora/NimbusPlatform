<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\ContractStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\ConstructionUnitRetirementException;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitRetirement;
use App\Models\Contract;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Baixa de unidade: a Gestão tira do Quadro de Vendas, a partir de uma data, a
 * unidade que deixou de existir -- e a devolve, pela reativação, se a baixa foi
 * engano ou a unidade voltou.
 *
 * A baixa tira unidade e valor de estoque das competências seguintes, e isso
 * alimenta Garantias e o Relatório. É decisão da Gestão em qualquer situação da
 * Emissão: `sales-boards.approve`, com motivo e autor, conferidos aqui -- a tela
 * só esconde e explica. Unidade sem história tem outra saída, a exclusão.
 *
 * As recusas, todas sem gravar nada:
 *
 * - data futura: baixa e reativação são fatos, como venda e distrato, e uma
 *   baixa agendada abriria a janela para vender a unidade antes da data;
 * - data dentro de competência publicada ({@see PublishedCompetenceBoundary}): a
 *   posição publicada é imutável, e o passado é assunto da retificação;
 * - ocupação da unidade na data ou depois -- contrato `[venda, distrato)` ou
 *   permuta `[início, encerramento)` que cruze `[data, ∞)`. Olhar só a data
 *   deixaria passar a venda posterior que reocupa a unidade baixada. O contrato
 *   distratado sem data de distrato ocupa para sempre, e tem mensagem própria;
 * - outra baixa aberta, ou encerrada depois da data -- os períodos de baixa não
 *   se sobrepõem. A anulada (período vazio) não conta.
 *
 * Ordem de locks, a regra única do Quadro: primeiro os ciclos em aberto da obra
 * com competência a partir do mês da data, do mais recente para o mais antigo
 * (`reference_month` e `id` decrescentes); depois a unidade; na reativação, por
 * fim a própria baixa. A aprovação trava o ciclo antes de derivar a fonte: sem
 * travar os ciclos aqui, uma baixa commitada no meio da aprovação deixaria a
 * competência publicada com a unidade e a data da baixa dentro dela, sem
 * aviso. Travados os ciclos, a última competência publicada é lida com
 * `sharedLock()`, para enxergar a publicação que a espera acabou de commitar. A
 * unidade travada serializa a baixa com a permuta, que também trava a unidade.
 */
class ConstructionUnitRetirementService
{
    /**
     * @throws AuthorizationException
     */
    public function retire(
        ConstructionUnit $unit,
        ?User $actor,
        CarbonImmutable $retiredOn,
        string $reason,
    ): ConstructionUnitRetirement {
        $actor = $this->authorize($actor);
        $reason = $this->normalizeReason($reason);
        $retiredOn = self::civilDay($retiredOn);
        $today = self::today();

        if ($retiredOn->greaterThan($today)) {
            throw ConstructionUnitRetirementException::futureDate($today);
        }

        $constructionId = $this->constructionOf((int) $unit->getKey());

        return DB::transaction(function () use ($unit, $actor, $retiredOn, $reason, $constructionId): ConstructionUnitRetirement {
            PublishedCompetenceBoundary::lockOpenCyclesReachedBy($constructionId, $retiredOn);
            $locked = $this->lockUnit((int) $unit->getKey(), $constructionId);

            $this->assertAfterPublishedCompetences($constructionId, $retiredOn);
            $this->assertNoOtherRetirement($locked, $retiredOn);
            $this->assertNoContractFrom($locked, $retiredOn);
            $this->assertNoExchangeFrom($locked, $retiredOn);

            return ConstructionUnitRetirement::query()->create([
                'construction_unit_id' => $locked->getKey(),
                'retired_on' => $retiredOn->toDateString(),
                'reason' => $reason,
                'retired_by_id' => $actor->getKey(),
            ]);
        });
    }

    /**
     * Encerra a baixa a partir de uma data -- a unidade volta a compor o Quadro
     * nela. A data igual à da baixa anula a baixa: o período fica vazio e ela
     * não vale em competência nenhuma, o que só é possível enquanto nenhuma
     * competência publicada a alcançou.
     *
     * @throws AuthorizationException
     */
    public function reactivate(
        ConstructionUnitRetirement $retirement,
        ?User $actor,
        CarbonImmutable $reactivatedOn,
        string $reason,
    ): ConstructionUnitRetirement {
        $actor = $this->authorize($actor);
        $reason = $this->normalizeReason($reason);
        $reactivatedOn = self::civilDay($reactivatedOn);
        $today = self::today();

        if ($reactivatedOn->greaterThan($today)) {
            throw ConstructionUnitRetirementException::futureReactivation($today);
        }

        $unitId = (int) $retirement->construction_unit_id;
        $constructionId = $this->constructionOf($unitId);

        return DB::transaction(function () use ($retirement, $actor, $reactivatedOn, $reason, $unitId, $constructionId): ConstructionUnitRetirement {
            PublishedCompetenceBoundary::lockOpenCyclesReachedBy($constructionId, $reactivatedOn);
            $this->lockUnit($unitId, $constructionId);

            $locked = ConstructionUnitRetirement::query()
                ->whereKey($retirement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->reactivated_on !== null) {
                throw ConstructionUnitRetirementException::alreadyReactivated(self::civilDay($locked->reactivated_on));
            }

            $retiredOn = self::civilDay($locked->retired_on);

            if ($reactivatedOn->lessThan($retiredOn)) {
                throw ConstructionUnitRetirementException::reactivationBeforeRetirement($retiredOn);
            }

            $this->assertAfterPublishedCompetences($constructionId, $reactivatedOn);

            $locked->forceFill([
                'reactivated_on' => $reactivatedOn->toDateString(),
                'reactivated_at' => CarbonImmutable::now(),
                'reactivated_by_id' => $actor->getKey(),
                'reactivation_reason' => $reason,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * A data mais antiga que a baixa (ou a reativação) pode ter: o dia seguinte
     * à última competência publicada, ou `null` quando a obra não tem nenhuma.
     */
    public function earliestEffectiveDate(ConstructionUnit $unit): ?CarbonImmutable
    {
        return PublishedCompetenceBoundary::firstOpenDay((int) $unit->construction_id);
    }

    /**
     * As competências em aberto que uma baixa (ou reativação) na data alcança:
     * fora de Aprovado e de Cancelado, do mês da data em diante. São as que
     * percebem a mudança na verificação de fonte e precisam ser recalculadas.
     *
     * @return Collection<int, SalesBoardCycle>
     */
    public function openCompetencesReachedBy(ConstructionUnit $unit, CarbonImmutable $date): Collection
    {
        return SalesBoardCycle::query()
            ->where('construction_id', $unit->construction_id)
            ->whereNotIn('status', self::closedStatuses())
            ->where('reference_month', '>=', $date->startOfMonth()->toDateString())
            ->orderBy('reference_month')
            ->orderBy('id')
            ->get();
    }

    private function authorize(?User $actor): User
    {
        if ($actor === null) {
            throw ConstructionUnitRetirementException::actorRequired();
        }

        SalesBoardApprovalAuthority::authorize($actor);

        return $actor;
    }

    /**
     * O empreendimento da unidade, lido antes da transação: os ciclos dele são
     * travados antes da unidade, e a leitura fora da transação não fixa o
     * instantâneo do `REPEATABLE READ` antes de os locks serem obtidos.
     */
    private function constructionOf(int $unitId): int
    {
        return (int) ConstructionUnit::query()->whereKey($unitId)->valueOrFail('construction_id');
    }

    /**
     * A unidade travada serializa as escritas sobre ela -- baixa, reativação e
     * permuta. Uma unidade que mudou de empreendimento entre a leitura e o lock
     * teria tido os ciclos errados travados: a baixa é recusada, sem gravar
     * nada, e pode ser repetida.
     */
    private function lockUnit(int $unitId, int $constructionId): ConstructionUnit
    {
        $unit = ConstructionUnit::query()
            ->whereKey($unitId)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $unit->construction_id !== $constructionId) {
            throw ConstructionUnitRetirementException::unitMoved();
        }

        return $unit;
    }

    private function assertAfterPublishedCompetences(int $constructionId, CarbonImmutable $date): void
    {
        $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth($constructionId, lockingRead: true);

        if (($lastPublished !== null) && $date->lessThanOrEqualTo($lastPublished->endOfMonth())) {
            throw ConstructionUnitRetirementException::reachesPublishedCompetence($date, $lastPublished);
        }
    }

    /**
     * Os períodos de baixa da unidade não se sobrepõem: a aberta precisa ser
     * reativada antes de outra baixa, e a encerrada só admite baixa nova a
     * partir da reativação. A anulada não vale em data nenhuma e não conta.
     */
    private function assertNoOtherRetirement(ConstructionUnit $unit, CarbonImmutable $retiredOn): void
    {
        $retirements = ConstructionUnitRetirement::query()
            ->where('construction_unit_id', $unit->getKey())
            ->orderBy('retired_on')
            ->orderBy('id')
            ->get();

        foreach ($retirements as $existing) {
            $start = self::civilDay($existing->retired_on);

            if ($existing->reactivated_on === null) {
                throw ConstructionUnitRetirementException::alreadyRetired($start);
            }

            $end = self::civilDay($existing->reactivated_on);

            if ($end->greaterThan($start) && $end->greaterThan($retiredOn)) {
                throw ConstructionUnitRetirementException::overlapsClosedRetirement($start, $end);
            }
        }
    }

    /**
     * Nenhum contrato pode ocupar a unidade na data da baixa nem depois dela.
     * O contrato excluído não ocupa; o distratado sem data de distrato ocupa
     * para sempre, pela regra temporal do Quadro.
     */
    private function assertNoContractFrom(ConstructionUnit $unit, CarbonImmutable $retiredOn): void
    {
        $contracts = Contract::query()
            ->where('construction_unit_id', $unit->getKey())
            ->orderBy('sale_date')
            ->orderBy('id')
            ->get(['id', 'code', 'status', 'sale_date', 'cancellation_date']);

        $day = $retiredOn->toDateString();

        foreach ($contracts as $contract) {
            $saleDay = $contract->sale_date?->toDateString();

            if ($saleDay === null) {
                continue;
            }

            $cancellationDay = $contract->cancellation_date?->toDateString();

            if (($contract->status === ContractStatus::Cancelled) && ($cancellationDay === null)) {
                throw ConstructionUnitRetirementException::cancelledContractWithoutDate((string) $contract->code);
            }

            if ($cancellationDay === null) {
                throw ConstructionUnitRetirementException::occupiedByContract((string) $contract->code, null);
            }

            if (($cancellationDay > $day) && ($cancellationDay > $saleDay)) {
                throw ConstructionUnitRetirementException::occupiedByContract(
                    (string) $contract->code,
                    CarbonImmutable::parse($cancellationDay),
                );
            }
        }
    }

    /**
     * Nenhuma permuta pode valer na data da baixa nem depois dela.
     */
    private function assertNoExchangeFrom(ConstructionUnit $unit, CarbonImmutable $retiredOn): void
    {
        $exchanges = ConstructionUnitExchange::query()
            ->where('construction_unit_id', $unit->getKey())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get(['id', 'effective_from', 'ended_on']);

        foreach ($exchanges as $exchange) {
            if ($exchange->effective_from === null) {
                continue;
            }

            $start = self::civilDay($exchange->effective_from);
            $end = $exchange->ended_on === null ? null : self::civilDay($exchange->ended_on);

            if (($end !== null) && ($end->lessThanOrEqualTo($start) || $end->lessThanOrEqualTo($retiredOn))) {
                continue;
            }

            throw ConstructionUnitRetirementException::exchangeInForce($start, $end);
        }
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH) {
            throw ConstructionUnitRetirementException::reasonRequired(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH);
        }

        return mb_substr($reason, 0, SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH);
    }

    /**
     * @return list<string>
     */
    private static function closedStatuses(): array
    {
        return [SalesBoardCycleStatus::Approved->value, SalesBoardCycleStatus::Cancelled->value];
    }

    private static function civilDay(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d'))->startOfDay();
    }

    private static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(BusinessTime::dateString());
    }
}
