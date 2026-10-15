<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuIndexAnomalyType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use Carbon\CarbonImmutable;

/**
 * Uma observação que a fonte entregou e o registro não aceitou, com o que for
 * preciso para revisá-la: o valor recebido (normalizado, quando legível) e a
 * representação crua da fonte, o motivo e, quando houver, o valor de referência
 * (o registrado na mesma data, ou a observação anterior no salto suspeito).
 */
final readonly class IndexRateAnomaly
{
    public function __construct(
        public PuIndexer $indexer,
        public ?CarbonImmutable $rateDate,
        public string $source,
        public PuIndexAnomalyType $type,
        public string $reason,
        public ?string $receivedValue = null,
        public ?string $rawValue = null,
        public ?string $externalSeriesCode = null,
        public ?string $referenceValue = null,
        public ?CarbonImmutable $referenceDate = null,
    ) {}

    /**
     * Identidade da anomalia: a mesma fonte informando a mesma coisa para a mesma
     * data, pelo mesmo motivo. Um valor diferente é outra anomalia.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->indexer->value,
            $this->source,
            $this->rateDate?->toDateString() ?? '',
            $this->type->value,
            $this->receivedValue ?? '',
            $this->receivedValue === null ? ($this->rawValue ?? '') : '',
        ]));
    }
}
