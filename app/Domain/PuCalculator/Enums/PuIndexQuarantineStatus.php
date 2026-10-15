<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Situação de uma observação em quarentena (Fase 8.1).
 *
 * Só `approved` produziu observação aceita, e sempre pelo caminho governado
 * (registro de observação ou correção). `superseded` é encerramento sem
 * decisão humana: outra observação aceita ou outra resposta da fonte tomou o
 * lugar desta; a evidência continua aqui.
 */
enum PuIndexQuarantineStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Aguardando revisão',
            self::Approved => 'Aprovada',
            self::Rejected => 'Recusada',
            self::Superseded => 'Superada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingReview => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Superseded => 'gray',
        };
    }
}
