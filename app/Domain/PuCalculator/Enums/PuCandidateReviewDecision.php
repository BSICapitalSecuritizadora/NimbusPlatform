<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCandidateReviewDecision: string
{
    case Approve = 'approve';
    case Reject = 'reject';
}
