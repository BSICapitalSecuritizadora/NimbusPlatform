<?php

declare(strict_types=1);

namespace App\Support\PuCalculator;

use App\Domain\PuCalculator\Exceptions\PuIndexerCapabilityException;
use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;

/**
 * Recusas dos portões do PU em linguagem de tela (Fase 8): o que não pode ser
 * feito, por quê, que nada foi gravado e qual é o próximo passo. O código da
 * recusa continua na trilha de auditoria; aqui ele só some do texto.
 */
final class PuGateMessage
{
    public static function activationRefusal(PuOperationalActivationException $refusal, string $operation): string
    {
        return match ($refusal->reasonCode) {
            PuOperationalActivationException::REASON_GLOBALLY_DISABLED => sprintf(
                '%s indisponível: as operações do PU estão desligadas neste ambiente (interruptor PU_OPERATIONS_ENABLED, controlado pela infraestrutura — não pela tela). Nada foi gravado.',
                $operation,
            ),
            PuOperationalActivationException::REASON_NOT_CONFIGURED => sprintf(
                '%s indisponível: a emissão não tem parâmetros de PU configurados. Nada foi gravado. Próximo passo: configurar o cálculo de PU.',
                $operation,
            ),
            PuOperationalActivationException::REASON_NOT_ACTIVATED => sprintf(
                '%s indisponível: a operação do PU desta emissão ainda não foi ativada (ter parâmetros não ativa). Nada foi gravado. Próximo passo: avaliar a prontidão e registrar a ativação autorizada em "Operação do PU".',
                $operation,
            ),
            PuOperationalActivationException::REASON_SUSPENDED => sprintf(
                '%s indisponível: a operação do PU desta emissão está suspensa. Nada foi gravado. Próximo passo: tratar o motivo da suspensão e reativar em "Operação do PU", se autorizado.',
                $operation,
            ),
            default => self::withoutCode($refusal->getMessage()),
        };
    }

    public static function indexerRefusal(PuIndexerCapabilityException $refusal, string $operation): string
    {
        $indexer = $refusal->indexer !== null ? PuDisplay::indexerName($refusal->indexer) : 'O indexador configurado';

        return $refusal->reasonCode === PuIndexerCapabilityException::REASON_NOT_HOMOLOGATED
            ? sprintf('%s indisponível: %s não é certificado para uso oficial — só simulação. Nada foi gravado.', $operation, $indexer)
            : sprintf('%s indisponível: %s', $operation, self::withoutCode($refusal->getMessage()));
    }

    /**
     * Tira o código técnico do começo da mensagem do domínio ("CODIGO: texto").
     */
    public static function withoutCode(string $message): string
    {
        $stripped = preg_replace('/^[A-Z][A-Z0-9_]+:\s*/', '', trim($message));

        return is_string($stripped) && $stripped !== '' ? self::sentence($stripped) : $message;
    }

    private static function sentence(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
