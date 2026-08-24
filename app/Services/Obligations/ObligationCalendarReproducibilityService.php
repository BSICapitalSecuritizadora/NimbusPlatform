<?php

namespace App\Services\Obligations;

use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Models\Obligation;

class ObligationCalendarReproducibilityService
{
    public function __construct(private readonly BusinessCalendarYearService $yearService) {}

    /**
     * Avaliação somente leitura. Uma divergência nunca altera o vencimento histórico.
     *
     * @return array{status:string,message:?string,changes:list<array<string, mixed>>}
     */
    public function assess(Obligation $obligation): array
    {
        $resolution = $obligation->due_date_resolution ?? [];
        $calendarCode = $resolution['calendar_code'] ?? null;
        $calendarYears = $resolution['calendar_years'] ?? [];

        if (blank($calendarCode) || ! is_array($calendarYears) || $calendarYears === []) {
            return ['status' => 'not_applicable', 'message' => null, 'changes' => []];
        }

        $changes = [];

        foreach ($calendarYears as $snapshot) {
            if (! is_array($snapshot) || ! isset($snapshot['year'])) {
                continue;
            }

            $year = (int) $snapshot['year'];
            $current = $this->yearService->coverage((string) $calendarCode, $year);
            $revisionChanged = (int) ($snapshot['revision'] ?? 0) !== (int) $current['revision'];
            $checksumChanged = ($snapshot['checksum'] ?? null) !== $current['checksum'];
            $governanceBecameStale = $current['governance_status'] === 'stale';

            if (! $revisionChanged && ! $checksumChanged && ! $governanceBecameStale) {
                continue;
            }

            $changes[] = [
                'year' => $year,
                'calculated_revision' => (int) ($snapshot['revision'] ?? 0),
                'current_revision' => (int) $current['revision'],
                'calculated_checksum' => $snapshot['checksum'] ?? null,
                'current_checksum' => $current['checksum'],
                'current_governance_status' => $current['governance_status'],
            ];
        }

        if ($changes === []) {
            return [
                'status' => 'current',
                'message' => 'O calendário permanece na mesma revisão utilizada no cálculo.',
                'changes' => [],
            ];
        }

        $firstChange = $changes[0];

        return [
            'status' => 'review_required',
            'message' => sprintf(
                'Este vencimento foi calculado com a revisão %d de %d. A revisão atual é %d%s. O vencimento histórico foi preservado e requer revisão humana.',
                $firstChange['calculated_revision'],
                $firstChange['year'],
                $firstChange['current_revision'],
                $firstChange['current_governance_status'] === 'stale' ? ' e está marcada como stale' : '',
            ),
            'changes' => $changes,
        ];
    }
}
