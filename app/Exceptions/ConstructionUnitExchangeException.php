<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas da permuta extraordinária e do encerramento de permuta.
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

    public static function emissionNotInOperation(): self
    {
        return new self('A Emissão da unidade ainda está em elaboração: declare a permuta como posição inicial.');
    }

    public static function invalidValue(): self
    {
        return new self('Informe um valor de permuta válido, maior ou igual a zero.');
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
}
