<?php

namespace App\Enums;

enum MeasurementReceiptReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case LegacyUnreviewed = 'legacy_unreviewed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente de conferência documental',
            self::Approved => 'Aprovado documentalmente',
            self::Rejected => 'Rejeitado',
            self::LegacyUnreviewed => 'Legado — sem revisão documental individual',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::LegacyUnreviewed => 'gray',
        };
    }
}
