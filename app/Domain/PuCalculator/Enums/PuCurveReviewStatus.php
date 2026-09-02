<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCurveReviewStatus: string
{
    case NotApplicable = 'not_applicable';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isFinal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }
}
