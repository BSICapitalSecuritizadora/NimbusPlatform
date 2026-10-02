<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardApprovalResult;
use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardUnitClassification;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardCycleRectificationService;
use Illuminate\Support\Collection;

/**
 * Monta competências publicadas em sequência, para os fatos que chegam depois
 * da publicação: extemporâneos, revisões de venda, a ponte com a competência
 * anterior e a retificação.
 *
 * Cada competência passa pelo fluxo inteiro -- validação da construtora, análise
 * e aprovação --, com pessoas distintas em cada ponta, como acontece na
 * operação. A tabela das unidades é 500.000 e a política permite 10%: o piso de
 * toda venda é 450.000.
 */
final class ExtemporaneousFixture
{
    public const POLICY_FLOOR = '450000.00';

    /**
     * Um empreendimento pronto, com uma unidade financiada desde março e as
     * outras em estoque, e a competência de julho publicada.
     *
     * @return array{construction: Construction, units: list<ConstructionUnit>, financed: Contract, july: SalesBoardCycle, publication: SalesBoardApprovalResult}
     */
    public static function publishedJuly(int $units = 4): array
    {
        [$construction, $created] = CycleFixture::readyConstruction($units);

        $financed = DerivationFixture::contract($created[0], '2026-03-10', '600000.00');
        DerivationFixture::installment($financed, '001', '2026-04-10', '300000.00', '2026-04-09', '300000.00');
        DerivationFixture::installment($financed, '002', '2026-12-10', '300000.00');

        $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
        $publication = self::publish($july);

        return [
            'construction' => $construction,
            'units' => $created,
            'financed' => $financed,
            'july' => $july->fresh(),
            'publication' => $publication,
        ];
    }

    /**
     * Julho publicado e, depois, o valor da venda financiada de março corrigido
     * na fonte: a posição de julho apurada hoje difere da publicada, e a
     * retificação tem o que corrigir.
     *
     * @return array{construction: Construction, units: list<ConstructionUnit>, financed: Contract, july: SalesBoardCycle, publication: SalesBoardApprovalResult}
     */
    public static function rectifiableJuly(): array
    {
        $scenario = self::publishedJuly();
        $scenario['financed']->forceFill(['sale_value' => '650000.00'])->save();

        return $scenario;
    }

    /**
     * Leva a competência do ciclo do jeito que estiver -- gerada ou em
     * validação -- até a publicação, pelo fluxo de sempre, e decide as
     * pendências pelo caminho que cada origem admite.
     */
    public static function publish(SalesBoardCycle $cycle, ?User $approver = null): SalesBoardApprovalResult
    {
        $review = self::analysis($cycle);
        ManagementReviewFixture::decideAll($review);

        return ManagementReviewFixture::approve($review, $approver);
    }

    /**
     * A competência validada pela construtora e com a análise da Gestão aberta.
     */
    public static function analysis(SalesBoardCycle $cycle): SalesBoardManagementReview
    {
        $builderReview = BuilderReviewFixture::open($cycle->fresh());
        BuilderReviewFixture::confirmAll($builderReview);
        BuilderReviewFixture::submit($builderReview);

        return ManagementReviewFixture::open($cycle->fresh());
    }

    public static function generateAugust(Construction $construction): SalesBoardCycle
    {
        return CycleFixture::generate($construction, '2026-08-01')->cycle;
    }

    public static function deriveAugust(Construction $construction): SalesBoardDerivedPosition
    {
        return DerivationFixture::derive($construction->fresh(), '2026-08-01');
    }

    public static function rectify(SalesBoardCycle $cycle, ?User $actor = null, string $reason = 'Venda da unidade lançada com valor errado.'): SalesBoardCycleRectification
    {
        return app(SalesBoardCycleRectificationService::class)
            ->open($cycle->fresh(), $actor ?? GovernanceFixture::approver(), $reason);
    }

    /**
     * Os movimentos congelados na versão vigente do ciclo, de um tipo e,
     * opcionalmente, de um timing.
     *
     * @return Collection<int, SalesBoardCycleMovement>
     */
    public static function movements(SalesBoardCycle $cycle, SalesBoardMovementType $type, ?SalesBoardMovementTiming $timing = null): Collection
    {
        return CycleFixture::currentBaseline($cycle)
            ->movements()
            ->where('movement_type', $type)
            ->when($timing !== null, fn ($query) => $query->where('timing', $timing->value))
            ->get();
    }

    /**
     * Um contrato com uma parcela única em aberto, vencendo depois do fim do ano.
     */
    public static function sale(ConstructionUnit $unit, string $saleDate, string $saleValue = '480000.00'): Contract
    {
        $contract = DerivationFixture::contract($unit, $saleDate, $saleValue);
        DerivationFixture::installment($contract, '001', '2026-12-20', $saleValue);

        return $contract;
    }

    /**
     * Um contrato quitado no próprio dia da venda: a parcela única paga na data.
     */
    public static function cashSale(ConstructionUnit $unit, string $saleDate, string $saleValue = '480000.00'): Contract
    {
        $contract = DerivationFixture::contract($unit, $saleDate, $saleValue);
        DerivationFixture::installment($contract, '001', $saleDate, $saleValue, $saleDate, $saleValue);

        return $contract;
    }

    /**
     * Uma competência anterior congelada, com uma linha de estoque por unidade
     * do empreendimento: a âncora da derivação do mês seguinte.
     *
     * Gravada direto, em lote, sem passar pela geração -- serve para medir a
     * leitura da âncora em empreendimentos grandes, não o caminho que a cria.
     * Sem publicação: a âncora usa a versão vigente do ciclo.
     */
    public static function frozenAnchor(Construction $construction, string $referenceMonth = '2026-06-01'): SalesBoardCycleBaseline
    {
        $cycle = SalesBoardCycle::factory()
            ->forConstruction($construction)
            ->referenceMonth($referenceMonth)
            ->create(['status' => SalesBoardCycleStatus::Approved]);

        $baseline = SalesBoardCycleBaseline::factory()->create(['sales_board_cycle_id' => $cycle->getKey()]);
        $cycle->forceFill(['current_baseline_id' => $baseline->getKey()])->save();

        $now = now();
        $rows = ConstructionUnit::query()
            ->where('construction_id', $construction->getKey())
            ->orderBy('id')
            ->get(['id', 'block', 'unit'])
            ->map(fn (ConstructionUnit $unit): array => [
                'sales_board_cycle_baseline_id' => $baseline->getKey(),
                'construction_unit_id' => $unit->getKey(),
                'block' => $unit->block,
                'unit' => $unit->unit,
                'classification' => SalesBoardUnitClassification::Stock->value,
                'unit_reference_value' => '500000.00',
                'source_fingerprint' => str_repeat('c', 64),
                'snapshot_fingerprint' => str_repeat('d', 64),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            SalesBoardCycleLine::query()->insert($chunk);
        }

        return $baseline;
    }

    /**
     * Os códigos dos achados da posição, com a contagem.
     *
     * @return array<string, int>
     */
    public static function issueCounts(SalesBoardDerivedPosition $position): array
    {
        return array_count_values(DerivationFixture::issueCodes($position));
    }
}
