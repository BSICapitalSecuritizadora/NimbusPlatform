<?php

namespace App\Exceptions;

use App\Models\Contract;
use App\Support\SalesBoards\SalesBoardPlausibility;
use App\Support\SalesBoards\UnitRetirementConflict;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas da permuta: a posição inicial, a permuta extraordinária, o
 * encerramento (e o distrato do contrato de permuta que ele grava, inclusive
 * depois, na permuta encerrada sem ele), a substituição, a data anterior ao
 * piso de 1990 e a permuta sobre unidade baixada.
 *
 * Situações previsíveis do domínio, e não defeitos: viram mensagem para quem
 * está na tela.
 */
class ConstructionUnitExchangeException extends RuntimeException implements ShouldntReport
{
    public static function actorRequired(): self
    {
        return new self('O registro da permuta exige um usuário identificado.');
    }

    public static function reasonRequired(int $minimumLength): self
    {
        return new self(sprintf(
            'Informe o motivo, com pelo menos %d caracteres. Ele fica registrado na permuta e na auditoria.',
            $minimumLength,
        ));
    }

    /**
     * Permuta extraordinária ou encerramento pedidos antes de o Quadro usar a
     * obra: ali a permuta ainda é posição inicial.
     */
    public static function initialPositionStillOpen(): self
    {
        return new self('A Emissão da unidade ainda está em elaboração e a obra não tem competência apurada pelo Quadro de Vendas: declare a permuta como posição inicial.');
    }

    /**
     * A posição inicial já foi usada: a permuta nova é da Gestão.
     */
    public static function initialPositionFrozen(): self
    {
        return new self('A posição inicial de permuta desta unidade já foi usada pelo Quadro de Vendas (a obra tem competência apurada ou a Emissão está em operação). Uma permuta nova é registrada pela Gestão como permuta extraordinária.');
    }

    /**
     * Nenhuma data que decide o Quadro de Vendas é anterior ao piso: quase
     * sempre é o ano digitado com um dígito a menos (0026) ou trocado (1026).
     */
    public static function dateBeforeMinimumYear(CarbonImmutable $date): self
    {
        return new self(sprintf(
            'A data %s é anterior a 01/01/%d: confira o ano. Nenhuma data da carteira é anterior a %d, e a apuração do Quadro de Vendas bloquearia a competência.',
            $date->format('d/m/Y'),
            SalesBoardPlausibility::MINIMUM_YEAR,
            SalesBoardPlausibility::MINIMUM_YEAR,
        ));
    }

    public static function invalidValue(): self
    {
        return new self('Informe um valor de permuta maior que zero: permuta sem valor não compõe o Quadro de Vendas.');
    }

    public static function contractOfAnotherUnit(): self
    {
        return new self('O contrato informado não é desta unidade.');
    }

    public static function overlapsExistingExchange(CarbonImmutable $effectiveFrom): self
    {
        return new self(sprintf(
            'A unidade já tem permuta vigente em %s ou depois. Encerre a permuta atual antes de registrar outra: '
                .'duas permutas ao mesmo tempo deixam a posição da unidade indeterminada.',
            $effectiveFrom->format('d/m/Y'),
        ));
    }

    /**
     * A permuta valeria num período em que a unidade está baixada: baixada, ela
     * não compõe o Quadro, e a permuta não teria o que ocupar.
     */
    public static function unitRetired(UnitRetirementConflict $conflict): self
    {
        return new self($conflict->describe(null));
    }

    public static function reachesPublishedCompetence(CarbonImmutable $date, CarbonImmutable $lastPublishedMonth): self
    {
        return new self(sprintf(
            'A data %s cai em competência já aprovada e publicada: a posição publicada não é reescrita. '
                .'A data precisa ser posterior a %s.',
            $date->format('d/m/Y'),
            $lastPublishedMonth->endOfMonth()->format('d/m/Y'),
        ));
    }

    public static function alreadyEnded(): self
    {
        return new self('Esta permuta já está encerrada.');
    }

    public static function endBeforeStart(CarbonImmutable $effectiveFrom): self
    {
        return new self(sprintf(
            'O encerramento precisa ser posterior ao início da permuta, %s: a permuta deixa de valer no próprio dia do encerramento.',
            $effectiveFrom->format('d/m/Y'),
        ));
    }

    /**
     * O encerramento distrataria um contrato que quem confirmou não viu -- o
     * contrato foi cadastrado, ou trocou, depois de o modal abrir.
     */
    public static function contractCancellationNotConfirmed(Contract $contract, CarbonImmutable $endedOn): self
    {
        return new self(sprintf(
            'Encerrar esta permuta em %s distrata o contrato de permuta %s, e esse distrato não foi confirmado. Abra o encerramento de novo e confirme o distrato do contrato: nada foi gravado.',
            $endedOn->format('d/m/Y'),
            (string) $contract->code,
        ));
    }

    public static function cancellationMustHaveHappened(CarbonImmutable $endedOn): self
    {
        return new self(sprintf(
            'O encerramento em %s distrataria o contrato de permuta, e distrato é fato: só se registra depois de acontecer. Informe uma data até hoje.',
            $endedOn->format('d/m/Y'),
        ));
    }

    public static function exchangeContractStartsAfterEnd(Contract $contract): self
    {
        return new self(sprintf(
            'O contrato de permuta %s só começa em %s, depois do encerramento pedido: confira a data do encerramento ou a data da venda do contrato.',
            (string) $contract->code,
            $contract->sale_date?->format('d/m/Y') ?? '—',
        ));
    }

    public static function exchangeContractCancelledLater(Contract $contract): self
    {
        return new self(sprintf(
            'O contrato de permuta %s já tem distrato lançado em %s, depois da data pedida. Encerre a permuta na data do distrato, ou corrija a data do distrato no contrato.',
            (string) $contract->code,
            $contract->cancellation_date?->format('d/m/Y') ?? '—',
        ));
    }

    public static function ambiguousExchangeContract(CarbonImmutable $date): self
    {
        return new self(sprintf(
            'A unidade tem mais de um contrato marcado como permutado ocupando-a em %s: não dá para saber qual deles a permuta formaliza. Corrija os contratos da unidade antes de encerrar a permuta.',
            $date->format('d/m/Y'),
        ));
    }

    /**
     * O distrato da permuta encerrada é pedido numa permuta que ainda vale: o
     * caminho é encerrá-la.
     */
    public static function exchangeStillInForce(): self
    {
        return new self('Esta permuta ainda está vigente: use “Encerrar permuta”, que distrata o contrato de permuta na mesma data.');
    }

    public static function noExchangeContractToCancel(CarbonImmutable $endedOn): self
    {
        return new self(sprintf(
            'Nenhum contrato de permuta continua ocupando a unidade depois do encerramento em %s: não há distrato a registrar. '
                .'Se a permuta foi substituída, o contrato segue com a permuta que a sucedeu.',
            $endedOn->format('d/m/Y'),
        ));
    }

    /**
     * A permuta terminou dentro da posição publicada: o distrato com essa data
     * é fato do contrato e entra como extemporâneo pela edição do contrato.
     */
    public static function endedInsidePublishedCompetence(CarbonImmutable $endedOn, CarbonImmutable $lastPublishedMonth): self
    {
        return new self(sprintf(
            'A permuta foi encerrada em %s, dentro de competência já publicada (até %s): a posição publicada não é reescrita, e o distrato com essa data não é gravado por aqui. '
                .'Registre o distrato no próprio contrato (Contratos › editar, status Distratado e a data do distrato): ele entra como movimento extemporâneo na competência seguinte.',
            $endedOn->format('d/m/Y'),
            $lastPublishedMonth->endOfMonth()->format('d/m/Y'),
        ));
    }

    /**
     * O contrato que seria distratado não é o que quem confirmou viu.
     */
    public static function endedExchangeCancellationNotConfirmed(Contract $contract, CarbonImmutable $endedOn): self
    {
        return new self(sprintf(
            'O distrato da permuta encerrada em %s é do contrato de permuta %s, e esse distrato não foi confirmado. Abra a ação de novo e confirme o contrato: nada foi gravado.',
            $endedOn->format('d/m/Y'),
            (string) $contract->code,
        ));
    }

    public static function substitutionBeforeStart(CarbonImmutable $effectiveFrom): self
    {
        return new self(sprintf(
            'A nova vigência precisa ser igual ou posterior ao início da permuta atual, %s. Igual ao início substitui a permuta desde o começo.',
            $effectiveFrom->format('d/m/Y'),
        ));
    }
}
