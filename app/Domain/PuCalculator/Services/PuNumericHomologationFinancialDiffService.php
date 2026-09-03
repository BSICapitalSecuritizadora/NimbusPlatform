<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCandidateCurve;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationComparisonResult;

final class PuNumericHomologationFinancialDiffService
{
    public function __construct(private readonly DecimalRounder $rounder) {}

    /**
     * @param  array{
     *     source?:string,
     *     reference_date?:string,
     *     document_id?:int,
     *     status?:string,
     *     confidence?:string,
     *     rows?:list<array{curve_date:string,unit_value:string}>
     * }|null  $reference
     */
    public function compare(
        PuCandidateCurve $candidate,
        ?array $reference,
    ): PuNumericHomologationComparisonResult {
        $candidateRows = array_map(fn ($row): array => [
            'curve_date' => $row->date->toDateString(),
            'unit_value' => $row->updatedUnitValue,
        ], $candidate->rows);

        return $this->compareRows($candidateRows, $reference);
    }

    /**
     * Exact-date financial comparison boundary shared by the in-memory numeric
     * homologation and the persisted external-validation workflow.
     *
     * @param  list<array{curve_date:string,unit_value:string}>  $candidateRows
     * @param  array{
     *     source?:string,
     *     reference_date?:string,
     *     document_id?:int,
     *     status?:string,
     *     confidence?:string,
     *     rows?:list<array{curve_date:string,unit_value:string}>
     * }|null  $reference
     */
    public function compareRows(
        array $candidateRows,
        ?array $reference,
    ): PuNumericHomologationComparisonResult {
        if ($reference === null || ($reference['rows'] ?? []) === []) {
            return new PuNumericHomologationComparisonResult(
                status: 'unavailable',
                reason: 'Nenhum benchmark externo estruturado e governado está disponível para comparação automática.',
                provenance: $reference === null ? [] : $this->provenance($reference),
                differences: [],
                tolerancePolicy: null,
            );
        }

        $candidateRows = collect($candidateRows)->keyBy('curve_date');
        $referenceRows = collect($reference['rows'])->keyBy('curve_date');
        $dates = $candidateRows->keys()->merge($referenceRows->keys())->unique()->sort()->values();
        $differences = [];
        $maximumAbsoluteDifference = '0.0000000000000000';
        $maximumRelativeDifference = '0.0000000000000000';

        foreach ($dates as $date) {
            $candidateRow = $candidateRows->get($date);
            $referenceRow = $referenceRows->get($date);
            $candidateValue = is_array($candidateRow) ? ($candidateRow['unit_value'] ?? null) : null;
            $referenceValue = is_array($referenceRow) ? ($referenceRow['unit_value'] ?? null) : null;

            if (! is_string($candidateValue) || ! is_string($referenceValue)) {
                $differences[] = [
                    'curve_date' => $date,
                    'field' => 'updated_unit_value',
                    'candidate' => $candidateValue,
                    'reference' => $referenceValue,
                    'absolute_difference' => null,
                    'relative_difference_percentage' => null,
                    'classification' => 'not_classified_without_both_values',
                ];

                continue;
            }

            $absolute = $this->rounder->absoluteDifference(
                $candidateValue,
                $referenceValue,
                DecimalRounder::UNIT_SCALE,
            );
            $relative = $this->relativeDifference($absolute, $referenceValue);

            if (bccomp($absolute, $maximumAbsoluteDifference, DecimalRounder::UNIT_SCALE) === 1) {
                $maximumAbsoluteDifference = $absolute;
            }

            if ($relative !== null
                && bccomp($relative, $maximumRelativeDifference, DecimalRounder::UNIT_SCALE) === 1) {
                $maximumRelativeDifference = $relative;
            }

            $differences[] = [
                'curve_date' => $date,
                'field' => 'updated_unit_value',
                'candidate' => $candidateValue,
                'reference' => $referenceValue,
                'absolute_difference' => $absolute,
                'relative_difference_percentage' => $relative,
                'classification' => 'reported_without_tolerance',
            ];
        }

        return new PuNumericHomologationComparisonResult(
            status: 'compared',
            reason: 'Diferenças reportadas sem classificação de tolerância; nenhuma política formal foi encontrada.',
            provenance: $this->provenance($reference),
            differences: $differences,
            maximumAbsoluteDifference: $maximumAbsoluteDifference,
            maximumRelativeDifference: $maximumRelativeDifference,
            tolerancePolicy: null,
        );
    }

    private function relativeDifference(string $absoluteDifference, string $reference): ?string
    {
        $absoluteReference = ltrim($reference, '-');

        if (bccomp($absoluteReference, '0', DecimalRounder::UNIT_SCALE) === 0) {
            return null;
        }

        return $this->rounder->round(
            bcmul(
                bcdiv($absoluteDifference, $absoluteReference, DecimalRounder::INTERNAL_SCALE),
                '100',
                DecimalRounder::INTERNAL_SCALE,
            ),
            DecimalRounder::UNIT_SCALE,
        );
    }

    /** @param array<string, mixed> $reference @return array<string, mixed> */
    private function provenance(array $reference): array
    {
        return [
            'source' => $reference['source'] ?? null,
            'reference_date' => $reference['reference_date'] ?? null,
            'document_id' => $reference['document_id'] ?? null,
            'status' => $reference['status'] ?? null,
            'confidence' => $reference['confidence'] ?? null,
        ];
    }
}
