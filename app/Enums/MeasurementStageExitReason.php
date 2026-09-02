<?php

namespace App\Enums;

enum MeasurementStageExitReason: string
{
    case Approved = 'approved';
    case RejectedTerminal = 'rejected_terminal';
    case ReturnedByRejection = 'returned_by_rejection';
    case ReturnedFromFinalization = 'returned_from_finalization';
    case Finalized = 'finalized';
    case Open = 'open';
    case Unknown = 'unknown';
}
