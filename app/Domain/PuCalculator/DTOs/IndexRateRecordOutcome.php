<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Models\IndexRate;
use Carbon\CarbonImmutable;

/**
 * O que aconteceu com UMA observação oferecida ao registro de índices.
 *
 * Só `created` grava. `unchanged` é a reimportação idempotente. Os conflitos e
 * as recusas não gravam nada e dizem por quê: valor diferente numa data já
 * registrada é correção (caminho próprio, com motivo e trilha), origem diferente
 * com o mesmo valor não troca a procedência, e um valor fora do padrão espera
 * confirmação explícita.
 */
final readonly class IndexRateRecordOutcome
{
    public const CREATED = 'created';

    public const UNCHANGED = 'unchanged';

    public const VALUE_CONFLICT = 'value_conflict';

    public const SOURCE_CONFLICT = 'source_conflict';

    public const NATURE_CONFLICT = 'nature_conflict';

    public const REJECTED = 'rejected';

    public const NEEDS_CONFIRMATION = 'needs_confirmation';

    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $status,
        public CarbonImmutable $date,
        public ?string $value,
        public ?string $reason = null,
        public ?IndexRate $existing = null,
        public array $warnings = [],
    ) {}

    public function wasCreated(): bool
    {
        return $this->status === self::CREATED;
    }

    public function isUnchanged(): bool
    {
        return $this->status === self::UNCHANGED;
    }

    public function isConflict(): bool
    {
        return in_array($this->status, [self::VALUE_CONFLICT, self::SOURCE_CONFLICT, self::NATURE_CONFLICT], true);
    }

    public function isRejected(): bool
    {
        return in_array($this->status, [self::REJECTED, self::NEEDS_CONFIRMATION], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'date' => $this->date->toDateString(),
            'value' => $this->value,
            'reason' => $this->reason,
            'existing_value' => $this->existing !== null ? (string) $this->existing->rate_value : null,
            'existing_source' => $this->existing?->source,
            'warnings' => $this->warnings,
        ];
    }
}
