<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Exceptions\PuGovernedHistoryProtectedException;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuHistoryAnchor;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use Illuminate\Database\Eloquent\Builder;

/**
 * Proteção do histórico financeiro governado do PU contra exclusão (Fase 7).
 *
 * A maior parte das tabelas do PU desce em cascata a partir de `emissions`
 * (versões, linhas da curva, eventos, parâmetros, obrigações, cálculos,
 * componentes, conciliação): apagar a emissão levava junto a curva homologada e
 * o esperado das obrigações, sem aviso -- só liquidação, conflito, promoção e
 * benchmark eram RESTRICT. Duas camadas fecham isso:
 *
 *  - banco: a primeira homologação grava a âncora da emissão
 *    ({@see EmissionPuHistoryAnchor}), com chave estrangeira RESTRICT. Daí em
 *    diante nem `DELETE` direto no banco nem exclusão em massa pelo query builder
 *    apagam a emissão -- e sem apagar a emissão nenhuma cascata dispara;
 *  - aplicação: o `delete()` do model recusa, com o motivo, a emissão com
 *    qualquer histórico governado, antes de chegar ao banco.
 *
 * A emissão sem histórico governado (nenhuma curva homologada, obrigação,
 * liquidação, conflito ou promoção) segue as regras de exclusão de antes; a tela
 * continua recusando também a que só tem curva gerada (`EmissionPolicy`).
 */
final class PuGovernedHistoryGuard
{
    /**
     * Grava a âncora da emissão, se ela ainda não tiver. Chamado dentro da
     * transação do fato governado, com a emissão JÁ travada (a checagem da chave
     * estrangeira pega trava compartilhada na emissão; com a exclusiva já tomada,
     * a ordem de travas não muda).
     */
    public function anchor(
        Emission $lockedEmission,
        ?EmissionPuCurveVersion $version,
        ?int $actorId,
        string $reason = EmissionPuHistoryAnchor::REASON_CURVE_HOMOLOGATED,
    ): void {
        if (EmissionPuHistoryAnchor::query()->where('emission_id', $lockedEmission->getKey())->exists()) {
            return;
        }

        EmissionPuHistoryAnchor::query()->create([
            'emission_id' => $lockedEmission->getKey(),
            'reason' => $reason,
            'curve_version_id' => $version?->getKey(),
            'anchored_by' => $actorId,
            'anchored_at' => now(),
        ]);
    }

    /**
     * Por que a emissão não pode ser apagada fisicamente, ou lista vazia.
     *
     * @return list<string>
     */
    public function deletionBlockers(Emission|int $emission): array
    {
        $emissionId = $emission instanceof Emission ? (int) $emission->getKey() : $emission;
        $probes = [
            'tem histórico financeiro governado do PU ancorado' => EmissionPuHistoryAnchor::query(),
            'tem curva de PU homologada (oficial ou histórica)' => EmissionPuCurveVersion::query()->whereNotNull('homologated_at'),
            'tem obrigações financeiras do PU' => EmissionPuObligation::query(),
            'tem liquidações do PU registradas' => EmissionPuSettlement::query(),
            'tem conflito de liquidação do PU' => EmissionPuSettlementConflict::query(),
            'tem promoção governada de curva do PU' => EmissionPuCurvePromotion::query(),
        ];
        $reasons = [];

        foreach ($probes as $reason => $query) {
            /** @var Builder<*> $query */
            if ($query->where('emission_id', $emissionId)->exists()) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * @throws PuGovernedHistoryProtectedException
     */
    public function assertDeletable(Emission $emission): void
    {
        $reasons = $this->deletionBlockers($emission);

        if ($reasons !== []) {
            throw new PuGovernedHistoryProtectedException((int) $emission->getKey(), $reasons);
        }
    }
}
