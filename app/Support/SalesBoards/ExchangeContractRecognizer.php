<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Actions\Contracts\ContractReconciler;
use App\DTOs\SalesBoards\SalesBoardDerivedLine;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardUnitClassification;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Services\SalesBoards\ConstructionUnitExchangeService;
use App\Services\SalesBoards\SalesBoardDerivationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Se um contrato é o contrato de uma permuta -- e, portanto, nem a venda nem o
 * distrato dele são movimento comercial.
 *
 * Permuta é troca, não venda: a unidade permutada não entra em "Vendas do mês",
 * e o contrato que formaliza a permuta também não. Do mesmo jeito, desfazer a
 * permuta distrata esse contrato ({@see ConstructionUnitExchangeService::end()}),
 * e esse distrato não é "Distrato do mês" -- seria a assimetria "0 venda, 1
 * distrato" de uma unidade que nunca foi vendida.
 *
 * Um predicado só para todos os leitores: a derivação do Quadro
 * ({@see SalesBoardDerivationService}) -- inclusive os fatos extemporâneos --,
 * o relatório de negociações (o caminho ao vivo e o dos movimentos congelados)
 * e a reimportação de contratos ({@see ContractReconciler}). Reconhecer o
 * contrato de permuta de dois jeitos faria o relatório contar o distrato que o
 * Quadro exclui.
 *
 * Decidido em memória, sobre as permutas da unidade já carregadas -- quem
 * chama carrega as permutas das unidades em lote.
 */
final class ExchangeContractRecognizer
{
    /**
     * A venda é o contrato de uma permuta.
     *
     * Dois jeitos de reconhecer: uma permuta vigente na data da venda aponta
     * para ele, ou ele está marcado como permutado e é o contrato da linha
     * classificada como permutada no fechamento (a permuta inicial costuma
     * nascer sem contrato).
     *
     * @param  Collection<int, ConstructionUnitExchange>  $exchangesOfUnit
     */
    public static function isExchangeSale(Contract $contract, Collection $exchangesOfUnit, ?SalesBoardDerivedLine $lineAtClose): bool
    {
        $contractId = (int) $contract->getKey();

        $namedByExchange = ($contract->sale_date !== null) && $exchangesOfUnit->contains(
            fn (ConstructionUnitExchange $exchange): bool => ($exchange->contract_id !== null)
                && ((int) $exchange->contract_id === $contractId)
                && $exchange->isEffectiveOn($contract->sale_date),
        );

        if ($namedByExchange) {
            return true;
        }

        return ($contract->status === ContractStatus::Exchanged)
            && ($lineAtClose?->classification === SalesBoardUnitClassification::Exchanged)
            && ($lineAtClose->contractId === $contractId);
    }

    /**
     * A mesma pergunta para quem lê contratos sem a posição do Quadro -- o
     * relatório de negociações da competência ainda não publicada, que não tem
     * linha classificada no fechamento.
     *
     * Sem a linha, o contrato marcado como permutado é tomado como contrato de
     * permuta: é o que a linha confirmaria quando a permuta está registrada, e
     * quando não está o Quadro bloqueia a competência
     * (`EXCHANGE_SOURCE_MISSING`) -- contá-lo como venda no relatório daria um
     * número que o Quadro não publicaria. A outra forma de reconhecer é a de
     * sempre: a permuta vigente na data da venda aponta para ele.
     *
     * @param  Collection<int, ConstructionUnitExchange>  $exchangesOfUnit
     */
    public static function isExchangeSaleOnRecord(Contract $contract, Collection $exchangesOfUnit): bool
    {
        return ($contract->status === ContractStatus::Exchanged)
            || self::isExchangeSale($contract, $exchangesOfUnit, null);
    }

    /**
     * O distrato é o de um contrato de permuta.
     *
     * Reconhecido sem o status -- depois do distrato ele é "distratado", e não
     * mais "permutado": o contrato é apontado por alguma permuta da unidade, ou a
     * unidade tinha, na véspera do distrato, uma permuta sem contrato vigente (o
     * contrato que a ocupava era o dela).
     *
     * @param  Collection<int, ConstructionUnitExchange>  $exchangesOfUnit
     */
    public static function isExchangeCancellation(Contract $contract, Collection $exchangesOfUnit): bool
    {
        if ($contract->cancellation_date === null) {
            return false;
        }

        $contractId = (int) $contract->getKey();
        $eve = CarbonImmutable::parse($contract->cancellation_date->toDateString())->subDay();

        return $exchangesOfUnit->contains(
            fn (ConstructionUnitExchange $exchange): bool => (($exchange->contract_id !== null) && ((int) $exchange->contract_id === $contractId))
                || (($exchange->contract_id === null) && $exchange->isEffectiveOn($eve)),
        );
    }

    /**
     * O valor da venda deste contrato fica fora da conferência de escala contra
     * a tabela da unidade: é contrato de permuta, e contrato de permuta não tem
     * preço de tabela.
     *
     * A derivação nunca mede a escala da linha permutada nem da venda de permuta
     * ({@see SalesBoardDerivationService}). A importação de contratos e o
     * relatório de plausibilidade seguem esta regra para não recusar o que a
     * derivação aceita: sem ela, a permuta cadastrada abaixo de um décimo da
     * tabela travava o arquivo mensal inteiro, reenviada igual.
     *
     * Conta o status como ficará -- o da planilha, na importação: o permutado; e
     * o distratado que desfez a permuta -- marcado como permutado até então,
     * apontado por alguma permuta da unidade ou ocupante de uma permuta sem
     * contrato na véspera do distrato ({@see self::isExchangeCancellation()}). O
     * ativo e o quitado continuam conferidos, como na derivação.
     *
     * @param  ContractStatus  $status  o status com que o contrato fica
     * @param  Contract|null  $onRecord  o contrato cadastrado, ou `null` quando a planilha o cria
     * @param  Collection<int, ConstructionUnitExchange>  $exchangesOfUnit
     */
    public static function isOutsideTablePrice(ContractStatus $status, ?Contract $onRecord, Collection $exchangesOfUnit): bool
    {
        if ($status === ContractStatus::Exchanged) {
            return true;
        }

        if (($status !== ContractStatus::Cancelled) || ($onRecord === null)) {
            return false;
        }

        $contractId = (int) $onRecord->getKey();

        return ($onRecord->status === ContractStatus::Exchanged)
            || $exchangesOfUnit->contains(
                fn (ConstructionUnitExchange $exchange): bool => ($exchange->contract_id !== null) && ((int) $exchange->contract_id === $contractId),
            )
            || self::isExchangeCancellation($onRecord, $exchangesOfUnit);
    }

    /**
     * A permuta cujo encerramento distratou o contrato, se foi o caso.
     *
     * É o distrato que a Gestão grava ao encerrar a permuta: a permuta terminou
     * exatamente na data do distrato, e o contrato é o dela pelo mesmo critério
     * de {@see self::isExchangeCancellation()}. Desfazer esse distrato pela
     * reimportação faria o contrato voltar a ocupar a unidade sem permuta.
     *
     * @param  Collection<int, ConstructionUnitExchange>  $exchangesOfUnit
     */
    public static function endingExchangeOf(Contract $contract, Collection $exchangesOfUnit): ?ConstructionUnitExchange
    {
        if ($contract->cancellation_date === null) {
            return null;
        }

        $contractId = (int) $contract->getKey();
        $cancellationDay = $contract->cancellation_date->toDateString();
        $eve = CarbonImmutable::parse($cancellationDay)->subDay();

        return $exchangesOfUnit->first(
            fn (ConstructionUnitExchange $exchange): bool => ($exchange->ended_on?->toDateString() === $cancellationDay)
                && ((($exchange->contract_id !== null) && ((int) $exchange->contract_id === $contractId))
                    || (($exchange->contract_id === null) && $exchange->isEffectiveOn($eve))),
        );
    }
}
