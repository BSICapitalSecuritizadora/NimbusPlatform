<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardManagementReviewStatus;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Invalida as análises que deixaram de falar do quadro vigente.
 *
 * A Gestão decidiu sobre fatos. Se a nova versão apresenta outros fatos, aquelas
 * decisões não se aplicam a eles -- e transportá-las seria dar por analisado o
 * que ninguém analisou. Nada é apagado: a análise substituída continua
 * consultável, com todas as conclusões da época, porque elas continuam
 * verdadeiras sobre a versão a que se referiam.
 *
 * O contrário também vale, e é por isso que a comparação é pelo resumo da
 * posição: um recálculo que só troca a origem material produz um quadro
 * idêntico, e mandar a Gestão decidir tudo de novo seria pedir que ela lesse
 * duas vezes exatamente as mesmas linhas.
 *
 * O estado do ciclo não é tocado aqui. Quem devolve a competência à construtora
 * quando uma versão material nasce é a Fase D, no serviço dela, e duplicar essa
 * regra faria as duas divergirem no dia em que uma delas mudasse.
 */
class SalesBoardManagementReviewSupersedingService
{
    public const REASON_MATERIAL_RECALCULATION = 'nova_versao_material';

    public function __construct(
        private readonly SalesBoardManagementReviewApplicability $applicability,
    ) {}

    /**
     * @return list<SalesBoardManagementReview> as análises substituídas
     */
    public function supersedeOutdated(SalesBoardCycle $cycle, SalesBoardCycleBaseline $newBaseline): array
    {
        return DB::transaction(function () use ($cycle, $newBaseline): array {
            $locked = SalesBoardCycle::query()
                ->whereKey($cycle->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $outdated = $this->applicability->reviewsOutdatedBy($locked, $newBaseline);

            if ($outdated === []) {
                return [];
            }

            $now = CarbonImmutable::now();

            foreach ($outdated as $review) {
                $review->forceFill([
                    'status' => SalesBoardManagementReviewStatus::Superseded,
                    'superseded_at' => $now,
                    'superseded_reason' => self::REASON_MATERIAL_RECALCULATION,
                ])->save();
            }

            return $outdated;
        });
    }
}
