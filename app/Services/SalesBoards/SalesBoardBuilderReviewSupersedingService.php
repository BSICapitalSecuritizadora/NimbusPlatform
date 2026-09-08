<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Invalida as validações que deixaram de falar do quadro vigente.
 *
 * A decisão funcional é antiga e vale a pena repetir: se a nova versão muda algo
 * que a construtora já conferiu, a conferência precisa ser refeita. Aproveitar a
 * validação anterior significaria entregar à Gestão uma concordância sobre
 * números que já não existem.
 *
 * O contrário também vale, e é por isso que a comparação é pelo resumo da
 * posição: um recálculo que só troca a origem material produz um quadro
 * idêntico, e devolver isso à construtora seria pedir que ela conferisse de novo
 * exatamente a mesma coisa.
 *
 * Nada é apagado. A validação substituída continua consultável, com tudo o que
 * foi declarado na época -- é registro do que a construtora afirmou naquele
 * momento, e essa afirmação continua verdadeira sobre aquela versão.
 */
class SalesBoardBuilderReviewSupersedingService
{
    public const REASON_MATERIAL_RECALCULATION = 'nova_versao_material';

    public function __construct(
        private readonly SalesBoardBuilderReviewApplicability $applicability,
    ) {}

    /**
     * @return list<SalesBoardBuilderReview> as validações substituídas
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
                    'status' => SalesBoardBuilderReviewStatus::Superseded,
                    'superseded_at' => $now,
                    'superseded_reason' => self::REASON_MATERIAL_RECALCULATION,
                ])->save();
            }

            $this->returnCycleToBuilder($locked);

            return $outdated;
        });
    }

    /**
     * A competência volta a depender da construtora.
     *
     * É a única regressão de estado que esta fase realiza, e ela é consequência
     * direta de uma nova versão material -- não uma decisão de análise. Devolver,
     * aprovar ou rejeitar por juízo da Gestão é da fase seguinte.
     */
    private function returnCycleToBuilder(SalesBoardCycle $cycle): void
    {
        if (! in_array($cycle->status, [SalesBoardCycleStatus::BuilderReview, SalesBoardCycleStatus::ManagementReview], true)) {
            return;
        }

        if ($cycle->status === SalesBoardCycleStatus::BuilderReview) {
            return;
        }

        $cycle->forceFill(['status' => SalesBoardCycleStatus::BuilderReview])->save();
    }
}
