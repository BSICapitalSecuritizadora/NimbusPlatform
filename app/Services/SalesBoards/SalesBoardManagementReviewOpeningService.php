<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Abre a análise da Gestão sobre a submissão da construtora.
 *
 * Idempotente e sob demanda, não automática. A alternativa -- criar a análise
 * num listener do envio da construtora -- foi descartada: ela acoplaria a
 * submissão à Fase E, faria um evento de fora produzir estado interno que
 * ninguém pediu, e obrigaria o serviço de submissão a saber o que a Gestão
 * precisa ver. Aqui o acoplamento é o mínimo possível: a Gestão abre quando vai
 * analisar, e abrir duas vezes devolve a mesma análise.
 *
 * A abertura **materializa** as pendências. Uma tela que derivasse a lista a
 * cada carregamento não teria onde guardar a decisão, e uma decisão sem linha
 * própria não sobrevive à primeira mudança de fonte. As origens:
 *
 * - cada divergência declarada pela construtora vira uma pendência;
 * - cada venda que o Nimbus congelou como fora da política vira uma pendência;
 * - cada venda cuja conformidade o Nimbus **não conseguiu determinar** também
 *   vira uma pendência. "Não foi possível avaliar" não é "está conforme", e
 *   deixá-la passar em silêncio seria publicar uma venda que ninguém analisou.
 *
 * A conformidade **não é recalculada**. O que se analisa é o veredito congelado
 * no movimento, apurado em centavos exatos contra a política vigente na data da
 * venda. Reler a política de hoje para reconstruir a decisão de então daria uma
 * resposta diferente sempre que a política tivesse mudado -- e a Gestão estaria
 * decidindo sobre um fato que nunca existiu.
 */
class SalesBoardManagementReviewOpeningService
{
    public function __construct(
        private readonly SalesBoardManagementReviewApplicability $applicability,
    ) {}

    public function open(SalesBoardCycle $cycle, ?User $actor = null): SalesBoardManagementReview
    {
        $cycle = $cycle->fresh();

        $existing = $this->applicability->activeDraft($cycle);

        if ($existing !== null) {
            return $existing->load('nonconformities');
        }

        return DB::transaction(function () use ($cycle, $actor): SalesBoardManagementReview {
            /**
             * O lock do ciclo é o que faz dois gestores clicando ao mesmo tempo
             * produzirem uma análise só. Sem ele, os dois leriam "nenhuma
             * análise aberta" e os dois criariam a sua -- com decisões
             * divergentes sobre os mesmos fatos.
             *
             * A ordem é a mesma das fases anteriores: ciclo primeiro.
             */
            $locked = SalesBoardCycle::query()
                ->whereKey($cycle->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->applicability->activeDraft($locked);

            if ($existing !== null) {
                return $existing->load('nonconformities');
            }

            if ($locked->status !== SalesBoardCycleStatus::ManagementReview) {
                throw SalesBoardManagementReviewException::cycleNotInManagement($locked->status);
            }

            $baseline = SalesBoardCycleBaseline::query()->find($locked->current_baseline_id);

            if (! $baseline instanceof SalesBoardCycleBaseline) {
                throw SalesBoardManagementReviewException::withoutCurrentBaseline();
            }

            $builderReview = $this->applicability->submittedBuilderReview($locked, $baseline);

            if (! $builderReview instanceof SalesBoardBuilderReview) {
                throw SalesBoardManagementReviewException::withoutSubmittedBuilderReview();
            }

            $review = $this->createReview($locked, $baseline, $builderReview, $actor);

            $this->materializeNonconformities($review, $baseline, $builderReview);

            return $review->load('nonconformities');
        });
    }

    private function createReview(
        SalesBoardCycle $cycle,
        SalesBoardCycleBaseline $baseline,
        SalesBoardBuilderReview $builderReview,
        ?User $actor,
    ): SalesBoardManagementReview {
        return SalesBoardManagementReview::query()->create([
            'sales_board_cycle_id' => $cycle->getKey(),
            'sales_board_cycle_baseline_id' => $baseline->getKey(),
            'sales_board_builder_review_id' => $builderReview->getKey(),
            'attempt' => $this->nextAttempt($cycle),
            'status' => SalesBoardManagementReviewStatus::Draft,
            'snapshot_fingerprint' => $baseline->snapshot_fingerprint,
            'opened_at' => CarbonImmutable::now(),
            'opened_by_user_id' => $actor?->getKey(),
            'source_changed' => false,
        ]);
    }

    /**
     * Cria uma pendência por fato a decidir, num único `insert`.
     *
     * As do sistema vêm primeiro porque são as objetivas -- o Nimbus já provou o
     * apontamento em centavos -- e as declaradas depois, que são as que exigem
     * confronto entre duas versões do fato. A ordem de criação é a ordem em que
     * a tela apresenta.
     */
    private function materializeNonconformities(
        SalesBoardManagementReview $review,
        SalesBoardCycleBaseline $baseline,
        SalesBoardBuilderReview $builderReview,
    ): void {
        $now = CarbonImmutable::now();

        $rows = [];

        foreach ($this->decidableSales($baseline) as $movement) {
            $origin = SalesBoardNonconformityOrigin::forConformity($movement->conformity_status);

            if ($origin === null) {
                continue;
            }

            $rows[] = [
                'sales_board_management_review_id' => $review->getKey(),
                'origin' => $origin->value,
                'sales_board_builder_divergence_id' => null,
                'sales_board_cycle_movement_id' => $movement->getKey(),
                'decision' => SalesBoardNonconformityDecision::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($this->declaredDivergences($builderReview) as $divergence) {
            $rows[] = [
                'sales_board_management_review_id' => $review->getKey(),
                'origin' => SalesBoardNonconformityOrigin::BuilderDeclared->value,
                'sales_board_builder_divergence_id' => $divergence->getKey(),
                'sales_board_cycle_movement_id' => null,
                'decision' => SalesBoardNonconformityDecision::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        SalesBoardManagementNonconformity::query()->insert($rows);
    }

    /**
     * As vendas congeladas que exigem uma conclusão da Gestão.
     *
     * `NonConform` e `Undetermined`. `Conform` fica de fora: o Nimbus avaliou e
     * a venda respeitou a política, e criar uma linha para cada venda regular
     * afogaria a análise no que está certo.
     *
     * `Undetermined` entra porque a ausência de veredito é ela própria o
     * problema. Um movimento tem exatamente um `conformity_status`, então cada
     * venda produz no máximo uma pendência -- e a unique de
     * `(análise, movimento)` garante isso sem depender deste código.
     *
     * @return list<SalesBoardCycleMovement>
     */
    private function decidableSales(SalesBoardCycleBaseline $baseline): array
    {
        return SalesBoardCycleMovement::query()
            ->where('sales_board_cycle_baseline_id', $baseline->getKey())
            ->where('movement_type', SalesBoardMovementType::Sale)
            ->whereIn('conformity_status', SalesBoardNonconformityOrigin::decidableConformityStatuses())
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return list<SalesBoardBuilderDivergence>
     */
    private function declaredDivergences(SalesBoardBuilderReview $builderReview): array
    {
        return SalesBoardBuilderDivergence::query()
            ->where('sales_board_builder_review_id', $builderReview->getKey())
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function nextAttempt(SalesBoardCycle $cycle): int
    {
        return ((int) SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->max('attempt')) + 1;
    }
}
