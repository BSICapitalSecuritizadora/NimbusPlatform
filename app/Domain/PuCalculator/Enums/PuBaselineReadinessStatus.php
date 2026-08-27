<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuBaselineReadinessStatus: string
{
    case Blocked = 'blocked';
    case ReadyForCandidateConfiguration = 'ready_for_candidate_configuration';
    case ReadyForNumericHomologation = 'ready_for_numeric_homologation';
    case ExternallyValidated = 'externally_validated';

    public function label(): string
    {
        return match ($this) {
            self::Blocked => 'Bloqueado',
            self::ReadyForCandidateConfiguration => 'Pronto para configuração candidata',
            self::ReadyForNumericHomologation => 'Pronto para homologação numérica',
            self::ExternallyValidated => 'Validado externamente',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Blocked => 'danger',
            self::ReadyForCandidateConfiguration => 'warning',
            self::ReadyForNumericHomologation => 'info',
            self::ExternallyValidated => 'success',
        };
    }
}
