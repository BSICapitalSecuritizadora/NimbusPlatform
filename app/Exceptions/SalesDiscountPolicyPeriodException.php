<?php

namespace App\Exceptions;

use App\DTOs\SalesBoards\SalesDiscountPolicyPeriodAssessment;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas do registro de uma política de desconto.
 *
 * São situações previsíveis -- período invertido, política escondida por outra,
 * substituição não confirmada ou que mudou enquanto o formulário estava aberto
 * -- e não defeitos. Viram mensagem para quem está na tela, não log de erro.
 */
class SalesDiscountPolicyPeriodException extends RuntimeException implements ShouldntReport
{
    public static function endsBeforeItStarts(): self
    {
        return new self('O fim da vigência precisa ser igual ou posterior ao início.');
    }

    public static function hiddenByLaterPolicy(SalesDiscountPolicyPeriodAssessment $assessment): self
    {
        return new self((string) $assessment->blockingMessage());
    }

    /**
     * A substituição confirmada precisa ser a mesma que o servidor encontra na
     * hora de gravar. Se outra pessoa registrou uma política enquanto o
     * formulário estava aberto, a confirmação dada valia para outra situação.
     */
    public static function substitutionNotConfirmed(SalesDiscountPolicyPeriodAssessment $assessment): self
    {
        return new self(sprintf(
            '%s Revise o período e confirme a substituição.',
            $assessment->substitutionMessage(),
        ));
    }
}
