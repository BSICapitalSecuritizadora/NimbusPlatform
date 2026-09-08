<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Lifecycle da decisão de promoção operacional. É uma dimensão própria: não
 * reutiliza `PuCurveReviewStatus` (review interno da candidate) nem
 * `PuCurveExternalValidationStatus` (validação externa independente), porque
 * aprovar a troca da curva vigente é uma terceira decisão, com outro revisor e
 * outro efeito.
 *
 * `Approved` e `Executed` são deliberadamente separados: a decisão humana e o
 * efeito operacional são eventos distintos, e a execução revalida tudo
 * imediatamente antes da troca.
 */
enum PuCurvePromotionStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Executed = 'executed';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pendente de aprovação',
            self::Approved => 'Aprovada, aguardando execução',
            self::Rejected => 'Rejeitada',
            self::Executed => 'Executada',
        };
    }

    /**
     * Decisão de review já tomada e imutável. `Executed` também é final: só se
     * alcança a partir de `Approved`.
     */
    public function isFinalDecision(): bool
    {
        return in_array($this, [self::Rejected, self::Executed], true);
    }

    public function isExecutable(): bool
    {
        return $this === self::Approved;
    }
}
