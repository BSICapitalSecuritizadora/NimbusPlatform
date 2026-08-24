<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCalendarHomologationStatus: string
{
    case Draft = 'draft';
    case ReadyForReview = 'ready_for_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::ReadyForReview => 'Pronta para revisão',
            self::Approved => 'Aprovada para recomendação',
            self::Rejected => 'Encerrada sem recomendação',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::ReadyForReview => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
