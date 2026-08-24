<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCalendarHomologationDecision: string
{
    case RecommendMigration = 'recommend_migration';
    case RejectCandidate = 'reject_candidate';
    case Inconclusive = 'inconclusive';

    public function label(): string
    {
        return match ($this) {
            self::RecommendMigration => 'Recomendar migração futura',
            self::RejectCandidate => 'Rejeitar calendário candidato',
            self::Inconclusive => 'Inconclusiva',
        };
    }
}
