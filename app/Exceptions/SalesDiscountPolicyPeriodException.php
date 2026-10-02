<?php

namespace App\Exceptions;

use App\DTOs\SalesBoards\SalesDiscountPolicyPeriodAssessment;
use App\Models\SalesDiscountPolicy;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas do registro de uma política de desconto.
 *
 * São situações previsíveis -- período invertido, limite fora da faixa ou com
 * mais de duas casas, política escondida por outra, competência publicada
 * alcançada, venda já registrada rejulgada por quem não é da Gestão,
 * substituição ou alcance retroativo não confirmados ou que mudaram enquanto o
 * formulário estava aberto -- e não defeitos. Viram mensagem para quem está na
 * tela, não log de erro.
 */
class SalesDiscountPolicyPeriodException extends RuntimeException implements ShouldntReport
{
    public static function actorRequired(): self
    {
        return new self('Não foi possível identificar quem está registrando a política.');
    }

    /**
     * A política rejulga venda já registrada e quem registra não tem a
     * autoridade da Gestão.
     */
    public static function requiresManagementAuthority(SalesDiscountPolicyPeriodAssessment $assessment): self
    {
        return new self((string) $assessment->managementAuthorityMessage());
    }

    public static function endsBeforeItStarts(): self
    {
        return new self('O fim da vigência precisa ser igual ou posterior ao início.');
    }

    /**
     * O limite é gravado em `decimal(5,2)`: uma terceira casa seria arredondada
     * pelo banco, e o limite gravado não seria o aprovado.
     */
    public static function invalidMaximumDiscount(): self
    {
        return new self(sprintf(
            'O desconto máximo precisa estar entre %d%% e %d%%, com no máximo duas casas decimais.',
            SalesDiscountPolicy::MINIMUM_DISCOUNT_PERCENT,
            SalesDiscountPolicy::MAXIMUM_DISCOUNT_PERCENT,
        ));
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

    public static function reachesApprovedCompetence(SalesDiscountPolicyPeriodAssessment $assessment): self
    {
        return new self((string) $assessment->approvedCompetenceMessage());
    }

    /**
     * O alcance confirmado precisa ser o que o servidor calcula na hora de
     * gravar. Sem confirmação nenhuma, ou com uma dada antes de o dia de negócio
     * virar, quem registra não viu as vendas que a política vai rejulgar.
     */
    public static function retroactivityNotConfirmed(SalesDiscountPolicyPeriodAssessment $assessment): self
    {
        return new self(sprintf(
            '%s Revise o período e confirme o alcance retroativo.',
            $assessment->retroactivityMessage(),
        ));
    }
}
