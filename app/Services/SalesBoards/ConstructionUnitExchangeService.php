<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\ConstructionUnitExchangeKind;
use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\ConstructionUnitExchangeException;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Permuta com a operação em curso: "Registrar permuta extraordinária" e
 * "Encerrar permuta".
 *
 * A posição inicial de permuta é declarada enquanto a Emissão está em
 * elaboração. Depois disso a permuta decide a classificação "permutado" de cada
 * competência, e mudá-la é decisão da Gestão (`sales-boards.approve`), com
 * motivo e autor gravados na própria permuta e trilha no Activitylog.
 *
 * Duas proteções que não são regra nova, e sim o que já vale no Quadro:
 *
 * - a data não alcança competência já aprovada e publicada -- a posição
 *   publicada é imutável, como na política de desconto;
 * - uma unidade não tem duas permutas vigentes ao mesmo tempo -- a derivação
 *   trata isso como permuta ambígua e bloqueia a competência.
 *
 * Nada é apagado: encerrar grava o fim da vigência, e a permuta continua
 * consultável. A competência em andamento percebe a mudança pela verificação
 * de fonte e é recalculada pelo caminho de sempre.
 */
class ConstructionUnitExchangeService
{
    public function registerExtraordinary(
        ConstructionUnit $unit,
        ?User $actor,
        mixed $exchangeValue,
        CarbonImmutable $effectiveFrom,
        ?int $contractId,
        string $reason,
    ): ConstructionUnitExchange {
        $actor = $this->authorize($actor);
        $reason = $this->normalizeReason($reason);
        $effectiveFrom = $effectiveFrom->startOfDay();

        $cents = IntegerMoney::cents($exchangeValue);

        if (($cents === null) || ($cents < 0)) {
            throw ConstructionUnitExchangeException::invalidValue();
        }

        return DB::transaction(function () use ($unit, $actor, $cents, $effectiveFrom, $contractId, $reason): ConstructionUnitExchange {
            $locked = $this->lockUnit($unit);

            $this->assertEmissionInOperation($locked);
            $this->assertAfterPublishedCompetences($locked, $effectiveFrom);

            if (($contractId !== null)
                && ! Contract::query()->whereKey($contractId)->where('construction_unit_id', $locked->getKey())->exists()) {
                throw ConstructionUnitExchangeException::contractOfAnotherUnit();
            }

            $overlaps = ConstructionUnitExchange::query()
                ->where('construction_unit_id', $locked->getKey())
                ->where(function ($query) use ($effectiveFrom): void {
                    $query->whereNull('ended_on')->orWhere('ended_on', '>', InclusiveDateBound::upperBound($effectiveFrom));
                })
                ->exists();

            if ($overlaps) {
                throw ConstructionUnitExchangeException::overlapsExistingExchange($effectiveFrom);
            }

            return $locked->exchanges()->create([
                'exchange_value' => IntegerMoney::decimalString($cents),
                'effective_from' => $effectiveFrom->toDateString(),
                'ended_on' => null,
                'contract_id' => $contractId,
                'kind' => ConstructionUnitExchangeKind::Extraordinary,
                'reason' => $reason,
                'created_by_id' => $actor->getKey(),
            ]);
        });
    }

    /**
     * Encerra a permuta: ela deixa de valer no próprio dia do encerramento
     * (vigência semiaberta), e a unidade volta a ser classificada pelo que o
     * contrato disser.
     */
    public function end(
        ConstructionUnitExchange $exchange,
        ?User $actor,
        CarbonImmutable $endedOn,
        string $reason,
    ): ConstructionUnitExchange {
        $actor = $this->authorize($actor);
        $reason = $this->normalizeReason($reason);
        $endedOn = $endedOn->startOfDay();

        return DB::transaction(function () use ($exchange, $actor, $endedOn, $reason): ConstructionUnitExchange {
            $unit = $this->lockUnit(ConstructionUnit::query()->findOrFail($exchange->construction_unit_id));

            $locked = ConstructionUnitExchange::query()
                ->whereKey($exchange->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->ended_on !== null) {
                throw ConstructionUnitExchangeException::alreadyEnded();
            }

            $this->assertEmissionInOperation($unit);

            $effectiveFrom = CarbonImmutable::parse($locked->effective_from->toDateString());

            if ($endedOn->lessThanOrEqualTo($effectiveFrom)) {
                throw ConstructionUnitExchangeException::endBeforeStart($effectiveFrom);
            }

            $this->assertAfterPublishedCompetences($unit, $endedOn);

            $locked->forceFill([
                'ended_on' => $endedOn->toDateString(),
                'ended_at' => CarbonImmutable::now(),
                'ended_by_id' => $actor->getKey(),
                'end_reason' => $reason,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * A última competência aprovada e publicada do empreendimento, se houver.
     */
    public function lastPublishedCompetence(ConstructionUnit $unit): ?CarbonImmutable
    {
        $month = SalesBoardCycle::query()
            ->where('construction_id', $unit->construction_id)
            ->where('status', SalesBoardCycleStatus::Approved)
            ->max('reference_month');

        return $month === null ? null : CarbonImmutable::parse($month)->startOfMonth();
    }

    private function authorize(?User $actor): User
    {
        if ($actor === null) {
            throw ConstructionUnitExchangeException::actorRequired();
        }

        SalesBoardApprovalAuthority::authorize($actor);

        return $actor;
    }

    /**
     * A unidade travada serializa os registros de permuta dela: dois
     * registros simultâneos não passam os dois pela checagem de sobreposição.
     */
    private function lockUnit(ConstructionUnit $unit): ConstructionUnit
    {
        return ConstructionUnit::query()
            ->whereKey($unit->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertEmissionInOperation(ConstructionUnit $unit): void
    {
        $emission = Emission::query()
            ->whereKey($unit->construction()->value('emission_id'))
            ->first();

        if (! $emission instanceof Emission || $emission->isInDraft()) {
            throw ConstructionUnitExchangeException::emissionNotInOperation();
        }
    }

    private function assertAfterPublishedCompetences(ConstructionUnit $unit, CarbonImmutable $date): void
    {
        $lastPublished = $this->lastPublishedCompetence($unit);

        if (($lastPublished !== null) && $date->lessThanOrEqualTo($lastPublished->endOfMonth())) {
            throw ConstructionUnitExchangeException::reachesPublishedCompetence($date, $lastPublished);
        }
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH) {
            throw ConstructionUnitExchangeException::reasonRequired(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH);
        }

        return mb_substr($reason, 0, SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH);
    }
}
