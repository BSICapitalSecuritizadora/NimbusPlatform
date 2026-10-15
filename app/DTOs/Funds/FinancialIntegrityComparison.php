<?php

declare(strict_types=1);

namespace App\DTOs\Funds;

use App\DTOs\BaseDTO;

/**
 * Row-level diff between two integrity snapshots of the same database.
 *
 * A matching digest means the table is byte-identical under the canonical
 * serialization; otherwise the added/removed/changed id lists pinpoint the
 * divergence. Comparing snapshots from different databases (or different
 * drivers) is not meaningful and is left to the caller to avoid.
 */
readonly class FinancialIntegrityComparison extends BaseDTO
{
    /**
     * @param  array<string, array{matches: bool, digest_before: ?string, digest_after: ?string, row_count_before: int, row_count_after: int, added_ids: list<int>, removed_ids: list<int>, changed_ids: list<int>}>  $tables
     */
    public function __construct(
        public bool $matches,
        public array $tables,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'matches' => $this->matches,
            'tables' => $this->tables,
        ];
    }
}
