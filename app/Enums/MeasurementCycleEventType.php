<?php

namespace App\Enums;

enum MeasurementCycleEventType: string
{
    case MeasurementCreated = 'measurement_created';
    case Submitted = 'submitted';
    case StageApproved = 'stage_approved';
    case StageRejected = 'stage_rejected';
    case FinalizationReturned = 'finalization_returned';
    case StagePaused = 'stage_paused';
    case StageResumed = 'stage_resumed';
    case PaymentRegistered = 'payment_registered';
    case ReceiptAttached = 'receipt_attached';
    case ReceiptDeleted = 'receipt_deleted';
    case Finalized = 'finalized';
    case EngineeringSnapshotCreated = 'engineering_snapshot_created';
    case EngineeringSnapshotInvalidated = 'engineering_snapshot_invalidated';

    public function changesStageVisit(): bool
    {
        return in_array($this, [
            self::Submitted,
            self::StageApproved,
            self::StageRejected,
            self::FinalizationReturned,
            self::Finalized,
        ], true);
    }
}
