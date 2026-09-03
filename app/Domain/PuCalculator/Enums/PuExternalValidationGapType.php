<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuExternalValidationGapType: string
{
    case CandidateWithoutReference = 'candidate_without_reference';
    case ReferenceWithoutCandidate = 'reference_without_candidate';
}
