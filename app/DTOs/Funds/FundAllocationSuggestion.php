<?php

namespace App\DTOs\Funds;

use App\Support\Funds\FundAllocationMoney;

/**
 * A proposal-only allocation suggestion derived from approved history.
 *
 * All money travels as integer cents; the DTO never approves, persists or
 * mutates anything — the caller feeds it into the draft workflow, where the
 * usual review and approval apply. Amounts are explainable: every line maps
 * back to the source revision's prior share, and the unallocated remainder
 * is always preserved, never silently distributed.
 */
class FundAllocationSuggestion
{
    public const REASON_NO_REFERENCE = 'no_reference';

    public const REASON_NO_HISTORY = 'no_history';

    public const REASON_ZERO_REFERENCE = 'zero_reference';

    /**
     * @param  list<array{fund_id: int, emission_id: int, suggested_cents: int, prior_cents: int|null, is_new: bool}>  $lines
     * @param  list<array{fund_id: int, prior_cents: int}>  $missingFunds
     * @param  list<int>  $newFundIds
     */
    public function __construct(
        public readonly int $bankApplicationId,
        public readonly string $competence,
        public readonly ?int $snapshotId,
        public readonly ?int $snapshotVersion,
        public readonly int $referenceCents,
        public readonly ?int $sourceRevisionId,
        public readonly ?string $sourceCompetence,
        public readonly array $lines,
        public readonly array $missingFunds,
        public readonly array $newFundIds,
        public readonly int $totalCents,
        public readonly int $unallocatedCents,
        public readonly ?string $reason,
    ) {}

    public function isActionable(): bool
    {
        return $this->reason === null;
    }

    public function reasonMessage(): ?string
    {
        return match ($this->reason) {
            self::REASON_NO_REFERENCE => 'Registre primeiro o saldo de referência desta competência para gerar uma sugestão.',
            self::REASON_NO_HISTORY => 'Não há competência anterior aprovada para esta aplicação. Informe os valores manualmente.',
            self::REASON_ZERO_REFERENCE => 'A competência anterior aprovada tem saldo de referência zero; não é possível derivar proporções. Informe os valores manualmente.',
            default => null,
        };
    }

    /**
     * @return list<array{fund_id: int, amount: string}>
     */
    public function toDraftLines(): array
    {
        return array_map(
            fn (array $line): array => [
                'fund_id' => $line['fund_id'],
                'amount' => FundAllocationMoney::fromCents($line['suggested_cents']),
            ],
            $this->lines
        );
    }
}
