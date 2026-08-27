<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuBaselineEvidenceStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pendente de revisão',
            self::Approved => 'Aprovada',
            self::Rejected => 'Rejeitada',
        };
    }
}
