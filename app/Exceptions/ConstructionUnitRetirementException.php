<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas da baixa de unidade e da reativação.
 *
 * Situações previsíveis do domínio, e não defeitos: viram mensagem para quem
 * está na tela, e nada é gravado quando uma delas acontece.
 */
class ConstructionUnitRetirementException extends RuntimeException implements ShouldntReport
{
    public static function actorRequired(): self
    {
        return new self('A baixa da unidade exige um usuário identificado.');
    }

    public static function reasonRequired(int $minimumLength): self
    {
        return new self(sprintf(
            'Informe o motivo, com pelo menos %d caracteres. Ele fica registrado na baixa e na auditoria.',
            $minimumLength,
        ));
    }

    /**
     * Baixa e reativação são fatos, como a venda e o distrato: uma data futura
     * abriria a janela para vender a unidade antes de ela sair do Quadro.
     */
    public static function futureDate(CarbonImmutable $today): self
    {
        return new self(sprintf(
            'A baixa é um fato, não um agendamento: a data de efeito não pode ser posterior a hoje (%s).',
            $today->format('d/m/Y'),
        ));
    }

    public static function futureReactivation(CarbonImmutable $today): self
    {
        return new self(sprintf(
            'A reativação é um fato, não um agendamento: a data de efeito não pode ser posterior a hoje (%s).',
            $today->format('d/m/Y'),
        ));
    }

    /**
     * A unidade trocou de empreendimento entre a leitura e o lock: os ciclos
     * travados eram os do empreendimento antigo.
     */
    public static function unitMoved(): self
    {
        return new self('A unidade mudou de empreendimento enquanto a baixa era registrada. Abra a unidade de novo e repita a operação: nada foi gravado.');
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

    public static function alreadyRetired(CarbonImmutable $retiredOn): self
    {
        return new self(sprintf(
            'A unidade já está baixada desde %s. Para mudar a data, reative a unidade e registre a baixa de novo.',
            $retiredOn->format('d/m/Y'),
        ));
    }

    public static function overlapsClosedRetirement(CarbonImmutable $retiredOn, CarbonImmutable $reactivatedOn): self
    {
        return new self(sprintf(
            'A unidade esteve baixada de %s a %s: a nova baixa precisa começar em %s ou depois.',
            $retiredOn->format('d/m/Y'),
            $reactivatedOn->subDay()->format('d/m/Y'),
            $reactivatedOn->format('d/m/Y'),
        ));
    }

    /**
     * Um contrato ocupa a unidade na data da baixa ou depois dela -- a venda
     * ainda ativa, o distrato posterior à data ou a venda que só começa depois.
     */
    public static function occupiedByContract(string $code, ?CarbonImmutable $until): self
    {
        if ($until === null) {
            return new self(sprintf(
                'O contrato %s ocupa a unidade: a baixa só pode valer a partir do distrato. Registre o distrato do contrato (ou reveja a data) antes da baixa.',
                $code,
            ));
        }

        return new self(sprintf(
            'O contrato %s ocupa a unidade até o distrato em %s: a baixa só pode valer a partir do distrato. Use uma data a partir de %s.',
            $code,
            $until->format('d/m/Y'),
            $until->format('d/m/Y'),
        ));
    }

    public static function cancelledContractWithoutDate(string $code): self
    {
        return new self(sprintf(
            'O contrato %s está distratado sem data de distrato: para o Quadro ele ainda ocupa a unidade. Informe a data do distrato antes de registrar a baixa.',
            $code,
        ));
    }

    public static function exchangeInForce(CarbonImmutable $effectiveFrom, ?CarbonImmutable $endedOn): self
    {
        return new self(sprintf(
            'A unidade tem permuta vigente a partir de %s%s: encerre a permuta ou use uma data a partir do encerramento.',
            $effectiveFrom->format('d/m/Y'),
            $endedOn === null ? '' : ' até o encerramento em '.$endedOn->format('d/m/Y'),
        ));
    }

    public static function alreadyReactivated(CarbonImmutable $reactivatedOn): self
    {
        return new self(sprintf(
            'Esta baixa já foi encerrada em %s.',
            $reactivatedOn->format('d/m/Y'),
        ));
    }

    public static function reactivationBeforeRetirement(CarbonImmutable $retiredOn): self
    {
        return new self(sprintf(
            'A reativação não pode ser anterior à baixa (%s). Com a mesma data da baixa, a baixa fica anulada.',
            $retiredOn->format('d/m/Y'),
        ));
    }
}
