<?php

namespace App\Exceptions;

use Exception;

class ObligationCalendarCoverageException extends Exception
{
    /**
     * @param  array<string, mixed>  $coverage
     */
    public function __construct(
        public readonly string $calendarCode,
        public readonly string $requiredDate,
        public readonly array $coverage,
    ) {
        parent::__construct(sprintf(
            'Não foi possível calcular a obrigação: o calendário %s/%d está %s (%s) e não pode degradar para segunda a sexta.',
            $calendarCode,
            (int) $coverage['year'],
            $coverage['state'],
            $coverage['coverage_status'] === 'complete'
                ? 'cobertura completa, sem confirmação operacional vigente'
                : sprintf('%d/%d decisões diárias materializadas', $coverage['covered_days'], $coverage['expected_days']),
        ));
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [
            'calendar_code' => $this->calendarCode,
            'required_date' => $this->requiredDate,
            'coverage' => $this->coverage,
        ];
    }
}
