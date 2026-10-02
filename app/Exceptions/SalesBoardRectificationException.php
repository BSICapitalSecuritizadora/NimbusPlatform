<?php

namespace App\Exceptions;

use App\Enums\SalesBoardCycleStatus;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas da retificação de uma competência publicada.
 *
 * Situações previsíveis -- a competência não é a última publicada, já há
 * retificação aberta, não há o que retificar, faltou o motivo --, e não
 * defeitos: viram mensagem para quem está na tela, dizendo o caminho a seguir.
 */
class SalesBoardRectificationException extends RuntimeException implements ShouldntReport
{
    public static function actorRequired(): self
    {
        return new self('A retificação da competência exige um usuário identificado.');
    }

    public static function reasonRequired(int $minimumLength): self
    {
        return new self(sprintf(
            'Informe o motivo, com pelo menos %d caracteres. Ele fica registrado na retificação, na versão e na auditoria.',
            $minimumLength,
        ));
    }

    public static function notApproved(SalesBoardCycleStatus $status): self
    {
        return new self(sprintf(
            'Só se retifica competência aprovada e publicada; esta está em "%s".',
            $status->label(),
        ));
    }

    public static function notLastPublished(string $referenceMonth, string $lastPublishedMonth): self
    {
        return new self(sprintf(
            'Só a última competência publicada do empreendimento pode ser retificada, e ela é %s. '
                .'A posição de %s se corrige para frente: o fato alterado entra como movimento extemporâneo na próxima competência.',
            $lastPublishedMonth,
            $referenceMonth,
        ));
    }

    public static function alreadyOpen(string $referenceMonth): self
    {
        return new self(sprintf(
            'A competência %s já está em retificação. Conclua a retificação em andamento ou desista dela antes de abrir outra.',
            $referenceMonth,
        ));
    }

    public static function notOpen(): self
    {
        return new self('Esta retificação já foi encerrada: publicada ou desistida.');
    }

    /**
     * A apuração de hoje é a posição publicada. Com a fonte alterada sem efeito
     * na posição, o texto diz isso, para ninguém procurar a diferença.
     */
    public static function nothingToRectify(string $referenceMonth, bool $sourceChanged): self
    {
        return new self($sourceChanged
            ? sprintf('A fonte de %s mudou depois da publicação, mas a posição apurada continua igual à publicada: não há o que retificar.', $referenceMonth)
            : sprintf('A posição apurada hoje para %s é igual à publicada: não há o que retificar. Corrija a fonte antes de abrir a retificação.', $referenceMonth));
    }

    /**
     * @param  list<string>  $blockers  os bloqueios da prontidão, já descritos
     */
    public static function sourceIncomplete(array $blockers): self
    {
        return new self('A fonte da competência está incompleta e a posição retificada não pode ser apurada: '
            .implode('; ', $blockers).'. Corrija a fonte antes de abrir a retificação.');
    }

    /**
     * Na aprovação: outra competência foi publicada depois desta enquanto a
     * retificação corria.
     */
    public static function noLongerLastPublished(string $referenceMonth, string $laterMonth): self
    {
        return new self(sprintf(
            'A competência %s deixou de ser a última publicada do empreendimento: %s foi publicada depois. '
                .'Desista desta retificação; a correção entra como movimento extemporâneo na próxima competência.',
            $referenceMonth,
            $laterMonth,
        ));
    }

    /**
     * Na aprovação: a versão em análise ficou igual à publicada.
     */
    public static function unchangedAtApproval(string $referenceMonth): self
    {
        return new self(sprintf(
            'A versão em análise de %s é igual à posição publicada: não há o que publicar. '
                .'Recalcule com a fonte corrigida ou desista da retificação.',
            $referenceMonth,
        ));
    }
}
