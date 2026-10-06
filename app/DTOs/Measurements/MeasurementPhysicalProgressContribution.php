<?php

namespace App\DTOs\Measurements;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Avanço físico que uma medição com Engenharia vigente soma a um plano.
 *
 * O valor sai do `engineering_snapshot` congelado na aprovação -- a evidência
 * que a Finalização confere --, e não da linha do cronograma, que continua com
 * a última gravação mesmo depois de a aprovação deixar de valer. `legacy` marca
 * a única exceção: aprovação anterior ao snapshot, em que a linha gravada pela
 * Engenharia é o único registro do que foi aprovado.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementPhysicalProgressContribution implements Arrayable
{
    public function __construct(
        public int $measurementId,
        public int $planSetId,
        public ?int $planLineId,
        public int $sequenceNumber,
        public ?CarbonImmutable $measurementDate,
        public int $basisPoints,
        public bool $legacy = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'measurement_id' => $this->measurementId,
            'plan_set_id' => $this->planSetId,
            'plan_line_id' => $this->planLineId,
            'sequence_number' => $this->sequenceNumber,
            'measurement_date' => $this->measurementDate?->toDateString(),
            'percent' => MeasurementPhysicalProgress::decimal($this->basisPoints),
            'legacy' => $this->legacy,
        ];
    }
}
