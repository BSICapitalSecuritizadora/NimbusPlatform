<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardApprovalResult;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesPriceConformityStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Services\SalesBoards\SalesBoardManagementReturnService;
use App\Services\SalesBoards\SalesBoardManagementReviewOpeningService;

/**
 * Monta competências já entregues à Gestão.
 *
 * Começa onde a {@see BuilderReviewFixture} termina: uma validação enviada sobre
 * a versão vigente, com o ciclo em análise da Gestão. É o mínimo para exercitar
 * a abertura, as decisões e o portão de publicação.
 */
final class ManagementReviewFixture
{
    /**
     * Motivo aceito pelo domínio: acima do piso de dez caracteres.
     */
    public const REASON = 'Conferido contra os registros da construtora.';

    /**
     * Uma competência com as sete seções confirmadas, sem divergência e sem
     * venda fora da política: o caminho limpo até a publicação.
     *
     * @return array{cycle: SalesBoardCycle, construction: Construction, units: array<string, ConstructionUnit>, contracts: array<string, Contract>, builderReview: SalesBoardBuilderReview}
     */
    public static function submittedCycle(): array
    {
        $scenario = BuilderReviewFixture::generatedCycle();

        $review = BuilderReviewFixture::open($scenario['cycle']);
        BuilderReviewFixture::confirmAll($review);

        $scenario['builderReview'] = BuilderReviewFixture::submit($review);

        return $scenario;
    }

    /**
     * A mesma competência, mas com a venda do mês fechada abaixo do mínimo
     * autorizado -- 400.000 contra uma tabela de 500.000 com 10% de desconto
     * permitido, ou seja, 50.000 abaixo do piso.
     *
     * @return array{cycle: SalesBoardCycle, construction: Construction, units: array<string, ConstructionUnit>, contracts: array<string, Contract>, builderReview: SalesBoardBuilderReview}
     */
    public static function submittedCycleWithNonConformSale(): array
    {
        $scenario = BuilderReviewFixture::generatedCycleWithNonConformSale();

        $review = BuilderReviewFixture::open($scenario['cycle']);
        BuilderReviewFixture::confirmAll($review);

        $scenario['builderReview'] = BuilderReviewFixture::submit($review);

        return $scenario;
    }

    /**
     * Uma competência simples de um empreendimento **de uma emissão dada**, já
     * validada e entregue à Gestão.
     *
     * Existe porque o ciclo tem identidade imutável: não dá para gerar dois
     * ciclos e depois mudar a emissão deles. Uma emissão com dois
     * empreendimentos precisa nascer assim, com os dois pendurados nela desde o
     * começo -- que é como ela existe na operação.
     *
     * @return array{cycle: SalesBoardCycle, construction: Construction, builderReview: SalesBoardBuilderReview}
     */
    public static function submittedCycleOn(Emission $emission, string $unitPrefix = '2'): array
    {
        $construction = Construction::factory()->create(['emission_id' => $emission->getKey()]);

        SalesDiscountPolicy::factory()
            ->forConstruction($construction)
            ->effectiveFrom('2020-01-01')
            ->allowing('10.00')
            ->create();

        $units = collect(range(1, 3))
            ->map(fn (int $number): ConstructionUnit => DerivationFixture::unit(
                $construction,
                $unitPrefix.(string) (10 + $number),
            ));

        $financed = DerivationFixture::contract($units[0], '2026-03-10', '900000.00');
        DerivationFixture::installment($financed, '001', '2026-04-10', '450000.00', '2026-04-09', '450000.00');
        DerivationFixture::installment($financed, '002', '2026-10-10', '450000.00');

        $cycle = CycleFixture::generate($construction)->cycle;

        $builderReview = BuilderReviewFixture::open($cycle);
        BuilderReviewFixture::confirmAll($builderReview);

        return [
            'cycle' => $cycle,
            'construction' => $construction,
            'builderReview' => BuilderReviewFixture::submit($builderReview),
        ];
    }

    /**
     * Uma competência cujo baseline congelou uma venda **sem conformidade
     * determinável**, ao lado de uma conforme e de uma fora da política.
     *
     * O estado é montado com o movimento escrito diretamente na versão vigente,
     * e não pela geração, por um motivo que vale registrar: **a derivação atual
     * recusa produzir este baseline**. Toda venda indeterminada emite um
     * bloqueador de prontidão (`SALE_UNIT_VALUE_MISSING` ou
     * `SALE_DISCOUNT_POLICY_MISSING`), e a geração se recusa a congelar uma
     * posição com bloqueador.
     *
     * Ou seja: o que estes testes exercitam é a rede de segurança, não o caminho
     * normal. Ela existe porque o baseline pode vir de dado legado, de escrita
     * fora do fluxo ou de uma derivação futura que afrouxe o bloqueador -- e em
     * qualquer desses casos a venda não pode chegar ao Quadro publicado sem
     * decisão da Gestão.
     *
     * Escrever o movimento não altera o `snapshot_fingerprint` do baseline, o
     * que preserva a aplicabilidade das revisões da Fase D/E e mantém o cenário
     * focado no que ele quer provar.
     *
     * @return array{cycle: SalesBoardCycle, construction: Construction, units: array<string, ConstructionUnit>, contracts: array<string, Contract>, builderReview: SalesBoardBuilderReview, undetermined: SalesBoardCycleMovement, nonConform: SalesBoardCycleMovement}
     */
    public static function submittedCycleWithUndeterminedSale(): array
    {
        $scenario = BuilderReviewFixture::generatedCycle();

        $baseline = CycleFixture::currentBaseline($scenario['cycle']);

        $undetermined = self::writeSaleMovement(
            $baseline,
            '901',
            SalesPriceConformityStatus::Undetermined,
            'O empreendimento não tinha política de desconto vigente na data da venda.',
        );

        $nonConform = self::writeSaleMovement(
            $baseline,
            '902',
            SalesPriceConformityStatus::NonConform,
            null,
        );

        $review = BuilderReviewFixture::open($scenario['cycle']);
        BuilderReviewFixture::confirmAll($review);

        $scenario['builderReview'] = BuilderReviewFixture::submit($review);
        $scenario['undetermined'] = $undetermined;
        $scenario['nonConform'] = $nonConform;

        return $scenario;
    }

    /**
     * Escreve uma venda congelada na versão indicada.
     *
     * A unidade e o contrato que a venda referencia nascem num empreendimento
     * **à parte**, e não no do ciclo. As FKs do movimento exigem linhas reais,
     * mas o cenário não quer que elas entrem na derivação: criá-las no
     * empreendimento do ciclo mudaria a fonte viva -- nova unidade, nova venda,
     * nova quitação a apurar -- e a competência ficaria obsoleta por um motivo
     * que nada tem a ver com o que se quer provar.
     *
     * Uma venda indeterminada não tem mínimo autorizado nem diferença -- é
     * justamente o que falta que impede o veredito -- e as colunas ficam nulas,
     * como a derivação as deixaria.
     */
    public static function writeSaleMovement(
        SalesBoardCycleBaseline $baseline,
        string $unitNumber,
        SalesPriceConformityStatus $status,
        ?string $reason,
    ): SalesBoardCycleMovement {
        $elsewhere = Construction::factory()->create([
            'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
        ]);

        $unit = DerivationFixture::unit($elsewhere, $unitNumber);
        $contract = DerivationFixture::contract($unit, '2026-07-18', '400000.00');

        $determinable = $status !== SalesPriceConformityStatus::Undetermined;

        return SalesBoardCycleMovement::factory()->create([
            'sales_board_cycle_baseline_id' => $baseline->getKey(),
            'movement_type' => SalesBoardMovementType::Sale,
            'construction_unit_id' => $unit->getKey(),
            'block' => $unit->block,
            'unit' => $unit->unit,
            'contract_id' => $contract->getKey(),
            'contract_code' => 'CT-'.$unitNumber,
            'event_date' => '2026-07-18',
            'sale_date' => '2026-07-18',
            'sale_value' => '400000.00',
            'unit_reference_value' => $determinable ? '500000.00' : null,
            'authorized_discount_basis_points' => $determinable ? 1000 : null,
            'minimum_authorized_value' => $determinable ? '450000.00' : null,
            'effective_discount_basis_points' => $determinable ? 2000 : null,
            'difference_value' => $determinable ? '-50000.00' : null,
            'conformity_status' => $status,
            'conformity_reason' => $reason,
        ]);
    }

    public static function open(SalesBoardCycle $cycle, ?User $actor = null): SalesBoardManagementReview
    {
        return app(SalesBoardManagementReviewOpeningService::class)->open($cycle->fresh(), $actor);
    }

    public static function decide(
        SalesBoardManagementNonconformity $nonconformity,
        SalesBoardNonconformityDecision $decision,
        ?string $reason = self::REASON,
        ?User $actor = null,
    ): SalesBoardManagementNonconformity {
        return app(SalesBoardManagementDecisionService::class)
            ->decide($nonconformity, $decision, $reason, $actor);
    }

    /**
     * Resolve tudo pelo caminho que cada origem admite: declaração da
     * construtora vira "não procede", venda fora da política vira "exceção
     * aprovada". Serve aos testes que querem chegar ao portão com o caminho
     * livre, sem repetir a matriz de decisões em cada um.
     */
    public static function decideAll(SalesBoardManagementReview $review, ?User $actor = null): void
    {
        foreach ($review->fresh()->nonconformities as $item) {
            self::decide(
                $item,
                match ($item->origin) {
                    SalesBoardNonconformityOrigin::BuilderDeclared => SalesBoardNonconformityDecision::Dismissed,
                    SalesBoardNonconformityOrigin::SystemSaleNonConform => SalesBoardNonconformityDecision::AcceptedException,
                },
                self::REASON,
                $actor,
            );
        }
    }

    public static function approve(
        SalesBoardManagementReview $review,
        ?User $actor = null,
        ?string $sourceChangeReason = null,
        bool $declaration = true,
    ): SalesBoardApprovalResult {
        $actor ??= User::factory()->create();

        return app(SalesBoardManagementApprovalService::class)
            ->approve($review->fresh(), $actor, $declaration, $sourceChangeReason);
    }

    /**
     * @return array{review: SalesBoardManagementReview, builderReview: SalesBoardBuilderReview}
     */
    public static function returnToBuilder(
        SalesBoardManagementReview $review,
        ?User $actor = null,
        string $reason = 'Precisamos da confirmação do contrato da unidade 102.',
    ): array {
        $actor ??= User::factory()->create();

        return app(SalesBoardManagementReturnService::class)
            ->returnToBuilder($review->fresh(), $actor, $reason);
    }

    public static function nonconformityOf(
        SalesBoardManagementReview $review,
        SalesBoardNonconformityOrigin $origin,
    ): SalesBoardManagementNonconformity {
        return $review->fresh()->nonconformities
            ->where('origin', $origin)
            ->firstOrFail();
    }
}
