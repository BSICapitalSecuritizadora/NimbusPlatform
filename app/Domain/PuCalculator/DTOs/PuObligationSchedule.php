<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

/**
 * O cronograma de obrigações que a curva oficial vigente descreve: as obrigações
 * (ativas e superadas) e as datas do cronograma informado que não correspondem a
 * nenhuma delas.
 */
final readonly class PuObligationSchedule
{
    /**
     * @param  list<PuObligationDraft>  $drafts
     * @param  list<string>  $unmatchedInformedDates
     */
    public function __construct(
        public ?int $curveVersionId,
        public ?string $calculationVersion,
        public array $drafts = [],
        public array $unmatchedInformedDates = [],
    ) {}
}
