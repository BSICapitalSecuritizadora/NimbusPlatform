<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Resultado da avaliação de prontidão operacional do PU de uma emissão (Fase 7).
 *
 * Prontidão técnica não autoriza nada: `technically_ready` diz que o sistema não
 * encontrou bloqueio, e a ativação continua sendo uma decisão humana autorizada
 * (com a revisão contratual documentada). Só `operationally_authorized` reflete
 * uma ativação vigente -- e ainda assim cai para `not_ready` se um bloqueio
 * aparecer depois dela.
 */
enum PuOperationalReadinessStatus: string
{
    case NotConfigured = 'not_configured';
    case NotReady = 'not_ready';
    case ReadyForReview = 'ready_for_review';
    case TechnicallyReady = 'technically_ready';
    case OperationallyAuthorized = 'operationally_authorized';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'PU não configurado',
            self::NotReady => 'Não pronta (há bloqueio)',
            self::ReadyForReview => 'Pronta para revisão (há pontos a decidir)',
            self::TechnicallyReady => 'Tecnicamente pronta (ativação ainda não autorizada)',
            self::OperationallyAuthorized => 'Operação do PU autorizada e ativa',
        };
    }

    /**
     * Uma decisão de ativação pode ser tomada sobre esta avaliação: nada bloqueia.
     */
    public function allowsActivation(): bool
    {
        return $this === self::ReadyForReview || $this === self::TechnicallyReady;
    }
}
