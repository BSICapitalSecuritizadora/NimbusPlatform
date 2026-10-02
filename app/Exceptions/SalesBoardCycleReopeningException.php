<?php

namespace App\Exceptions;

use App\Enums\SalesBoardCycleStatus;
use App\Models\Emission;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas da reabertura de uma competência cancelada.
 *
 * Situações previsíveis -- a competência não está cancelada, saiu da
 * automação, já tem posição registrada, uma competência posterior já foi
 * publicada --, e não defeitos: viram mensagem para quem está na tela.
 */
class SalesBoardCycleReopeningException extends RuntimeException implements ShouldntReport
{
    public static function actorRequired(): self
    {
        return new self('A reabertura da competência exige um usuário identificado.');
    }

    public static function reasonRequired(int $minimumLength): self
    {
        return new self(sprintf(
            'Informe o motivo da reabertura, com pelo menos %d caracteres. Ele fica registrado na competência e na auditoria.',
            $minimumLength,
        ));
    }

    public static function notReopenable(SalesBoardCycleStatus $status): self
    {
        return new self(sprintf(
            'Só uma competência cancelada pode ser reaberta; esta está em "%s".',
            $status->label(),
        ));
    }

    public static function constructionChangedEmission(): self
    {
        return new self('O empreendimento mudou de Emissão depois da geração deste ciclo: a competência não pode ser reaberta.');
    }

    public static function emissionInDraft(): self
    {
        return new self(sprintf(
            'A Emissão está em "%s": a competência mensal só existe depois da elaboração, e esta continua cancelada.',
            Emission::STATUS_OPTIONS[Emission::STATUS_DRAFT],
        ));
    }

    public static function competenceNotCovered(string $referenceMonth, string $emission): self
    {
        return new self(sprintf(
            'A competência %s não está coberta pela automação da Emissão "%s" (modo legado ou anterior à competência inicial): '
                .'ela segue no registro manual e o ciclo continua cancelado.',
            $referenceMonth,
            $emission,
        ));
    }

    public static function positionAlreadyRegistered(string $construction, string $referenceMonth): self
    {
        return new self(sprintf(
            'Já existe Quadro de Vendas registrado para %s em %s: reabrir criaria uma segunda posição.',
            $construction,
            $referenceMonth,
        ));
    }

    /**
     * A posterior publicada absorveu este mês: os fatos dele já são movimentos
     * "de competência sem posição" da publicação dela.
     */
    public static function laterCompetencePublished(string $referenceMonth, string $laterMonth): self
    {
        return new self(sprintf(
            'A competência %s não pode ser reaberta: %s já foi publicada e absorveu os fatos dela. '
                .'Corrija pela próxima competência ou pela retificação da última publicada.',
            $referenceMonth,
            $laterMonth,
        ));
    }

    /**
     * A posterior publicada não absorveu este mês como movimento -- foi apurada
     * antes do cancelamento, ou o mês não teve fato --, mas a posição dela já
     * reflete os fatos dele. Dizer que ela "absorveu os fatos" mandaria procurar
     * movimentos que não existem.
     */
    public static function laterPositionReflectsFacts(string $referenceMonth, string $lastPublishedMonth): self
    {
        return new self(sprintf(
            'A competência %s não pode ser reaberta: %s já foi publicada, e a posição publicada já reflete os fatos de %s. '
                .'Para corrigir a posição publicada, use a retificação da última competência publicada (%s).',
            $referenceMonth,
            $lastPublishedMonth,
            $referenceMonth,
            $lastPublishedMonth,
        ));
    }
}
