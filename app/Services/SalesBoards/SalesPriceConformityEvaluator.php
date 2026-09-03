<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ResolvedSalesDiscountPolicy;
use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\DTOs\SalesBoards\SalesPriceConformityResult;
use App\Models\Contract;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Responde se uma venda respeitou a política comercial vigente **quando foi
 * feita**.
 *
 * A data de avaliação é sempre `Contract.sale_date`, nunca hoje e nunca o fim
 * da competência: uma tabela reajustada em agosto não pode reprovar uma venda
 * fechada em julho, nem uma política afrouxada depois pode aprovar
 * retroativamente o que estava fora do limite na época.
 *
 * O veredito é monetário e exato. O desconto autorizado é um teto, então o
 * preço mínimo é o primeiro centavo que ainda o respeita, e a comparação é
 * `venda >= mínimo`. Nada disso passa por percentual formatado nem por `float`
 * -- o percentual efetivo devolvido no resultado é apresentação, não critério.
 *
 * Nada é persistido: registrar a não conformidade, tratá-la e aprovar exceção
 * são fases seguintes.
 */
class SalesPriceConformityEvaluator
{
    public function __construct(
        private readonly UnitValueResolver $unitValueResolver,
        private readonly SalesDiscountPolicyResolver $salesDiscountPolicyResolver,
    ) {}

    /**
     * Avalia um contrato, resolvendo por conta própria o valor da unidade e a
     * política vigentes na data da venda.
     */
    public function forContract(Contract $contract): SalesPriceConformityResult
    {
        $saleDate = $contract->sale_date;

        if ($saleDate === null) {
            return SalesPriceConformityResult::undetermined(
                evaluatedAt: CarbonImmutable::now()->startOfDay(),
                reason: 'O contrato não tem data de venda registrada.',
            );
        }

        $saleDate = CarbonImmutable::parse($saleDate->toDateString());

        $contract->loadMissing('constructionUnit');
        $constructionUnit = $contract->constructionUnit;

        if ($constructionUnit === null) {
            return SalesPriceConformityResult::undetermined(
                evaluatedAt: $saleDate,
                reason: 'O contrato não está vinculado a uma unidade.',
            );
        }

        return $this->evaluate(
            saleDate: $saleDate,
            saleValue: $contract->sale_value,
            unitValue: $this->unitValueResolver->forUnit($constructionUnit, $saleDate),
            policy: $this->salesDiscountPolicyResolver->forConstructionId(
                (int) $constructionUnit->construction_id,
                $saleDate,
            ),
        );
    }

    /**
     * Avalia a partir dos fatos já resolvidos.
     *
     * É por aqui que a varredura em lote entra: quem já resolveu valores e
     * políticas de uma obra inteira não precisa que cada contrato os resolva de
     * novo.
     */
    public function evaluate(
        CarbonImmutable $saleDate,
        mixed $saleValue,
        ResolvedUnitValue $unitValue,
        ResolvedSalesDiscountPolicy $policy,
    ): SalesPriceConformityResult {
        $saleValueCents = IntegerMoney::cents($saleValue);

        if ($saleValueCents === null) {
            return SalesPriceConformityResult::undetermined(
                evaluatedAt: $saleDate,
                reason: 'O contrato não tem valor de venda registrado.',
                referenceValueCents: $unitValue->valueCents,
                authorizedDiscountBasisPoints: $policy->maximumDiscountBasisPoints,
            );
        }

        if ($unitValue->isAbsent()) {
            return SalesPriceConformityResult::undetermined(
                evaluatedAt: $saleDate,
                reason: 'A unidade não tinha valor de referência conhecido na data da venda.',
                saleValueCents: $saleValueCents,
                authorizedDiscountBasisPoints: $policy->maximumDiscountBasisPoints,
            );
        }

        if ($policy->isAbsent()) {
            return SalesPriceConformityResult::undetermined(
                evaluatedAt: $saleDate,
                reason: 'O empreendimento não tinha política de desconto vigente na data da venda.',
                referenceValueCents: $unitValue->valueCents,
                saleValueCents: $saleValueCents,
            );
        }

        $authorizedDiscountBasisPoints = (int) $policy->maximumDiscountBasisPoints;

        /**
         * A coluna `decimal(5,2)` aceita até 999,99, e a validação de 0 a 100
         * vive na aplicação. Uma linha fora da faixa -- importada, corrigida à
         * mão no banco, escrita por um caminho futuro -- não pode derrubar uma
         * varredura de carteira nem, pior, produzir um piso calculado com um
         * desconto que ninguém poderia ter autorizado.
         */
        if (($authorizedDiscountBasisPoints < 0) || ($authorizedDiscountBasisPoints > IntegerMoney::BASIS_POINTS_SCALE)) {
            return SalesPriceConformityResult::undetermined(
                evaluatedAt: $saleDate,
                reason: 'A política de desconto vigente está fora da faixa de 0% a 100% e não permite apuração segura.',
                referenceValueCents: $unitValue->valueCents,
                saleValueCents: $saleValueCents,
                authorizedDiscountBasisPoints: $authorizedDiscountBasisPoints,
            );
        }

        $referenceValueCents = (int) $unitValue->valueCents;

        if ($referenceValueCents < 0) {
            return SalesPriceConformityResult::undetermined(
                evaluatedAt: $saleDate,
                reason: 'O valor de referência da unidade é negativo e não permite apuração segura.',
                referenceValueCents: $referenceValueCents,
                saleValueCents: $saleValueCents,
                authorizedDiscountBasisPoints: $authorizedDiscountBasisPoints,
            );
        }

        return SalesPriceConformityResult::evaluated(
            evaluatedAt: $saleDate,
            referenceValueCents: $referenceValueCents,
            saleValueCents: $saleValueCents,
            authorizedDiscountBasisPoints: $authorizedDiscountBasisPoints,
            minimumAuthorizedValueCents: IntegerMoney::minimumAfterDiscount(
                $referenceValueCents,
                $authorizedDiscountBasisPoints,
            ),
        );
    }
}
