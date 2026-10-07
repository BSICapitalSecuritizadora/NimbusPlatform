<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use Carbon\CarbonImmutable;

/**
 * Resultado da comparação entre o retrato APROVADO de uma versão e os insumos
 * contratuais vivos.
 *
 * `earliestAffectedDate` é a primeira data em que o cálculo com os insumos vivos
 * pode diferir do aprovado. Antes dela, os dois contratos produzem a mesma curva.
 */
final readonly class PuCurveChangeAssessment
{
    /**
     * @param  list<PuCurveInputChange>  $changes
     */
    public function __construct(
        public PuCurveChangeImpact $impact,
        public ?CarbonImmutable $earliestAffectedDate,
        public array $changes,
        public string $approvedFingerprint,
        public string $liveFingerprint,
        public ?CarbonImmutable $lastPersistedDate,
    ) {}

    public static function unchanged(string $fingerprint, ?CarbonImmutable $lastPersistedDate): self
    {
        return new self(PuCurveChangeImpact::NoCurveImpact, null, [], $fingerprint, $fingerprint, $lastPersistedDate);
    }

    public function hasContractualChange(): bool
    {
        return $this->changes !== [] && $this->impact !== PuCurveChangeImpact::NoCurveImpact;
    }

    /**
     * Última data que a versão ainda pode receber. Nula: sem limite contratual
     * (só o índice realizado e o horizonte limitam).
     */
    public function extensionLimit(): ?CarbonImmutable
    {
        return match ($this->impact) {
            PuCurveChangeImpact::FutureVersionRequired => $this->earliestAffectedDate?->subDay(),
            PuCurveChangeImpact::HistoricalReprocessRequired => $this->lastPersistedDate,
            default => null,
        };
    }

    public function summary(): string
    {
        if (! $this->hasContractualChange()) {
            return 'Os insumos contratuais vivos são os aprovados na versão.';
        }

        $what = implode('; ', array_map(
            fn (PuCurveInputChange $change): string => sprintf(
                '%s (%s%s)',
                $change->kind->label(),
                $change->key,
                $change->affectedFrom !== null ? ', a partir de '.$change->affectedFrom->format('d/m/Y') : '',
            ),
            array_slice($this->changes, 0, 5),
        ));
        $more = count($this->changes) > 5 ? sprintf(' e mais %d', count($this->changes) - 5) : '';

        return match ($this->impact) {
            PuCurveChangeImpact::HistoricalReprocessRequired => sprintf(
                'Os insumos contratuais mudaram desde a aprovação e alcançam dias já gravados (desde %s): %s%s. A versão foi mantida e precisa ser substituída por uma nova versão homologada.',
                $this->earliestAffectedDate?->format('d/m/Y') ?? 'o início da curva',
                $what,
                $more,
            ),
            PuCurveChangeImpact::FutureVersionRequired => sprintf(
                'Os insumos contratuais mudaram a partir de %s, depois do último dia gravado: %s%s. A versão avança no máximo até %s; a mudança só chega ao PU oficial por uma nova versão homologada.',
                $this->earliestAffectedDate?->format('d/m/Y') ?? '?',
                $what,
                $more,
                $this->extensionLimit()?->format('d/m/Y') ?? '?',
            ),
            default => 'Os insumos contratuais vivos são os aprovados na versão.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'impact' => $this->impact->value,
            'earliest_affected_date' => $this->earliestAffectedDate?->toDateString(),
            'extension_limit' => $this->extensionLimit()?->toDateString(),
            'last_persisted_date' => $this->lastPersistedDate?->toDateString(),
            'approved_fingerprint' => $this->approvedFingerprint,
            'live_fingerprint' => $this->liveFingerprint,
            'changes' => array_map(fn (PuCurveInputChange $change): array => $change->toArray(), $this->changes),
            'reason' => $this->summary(),
        ];
    }
}
