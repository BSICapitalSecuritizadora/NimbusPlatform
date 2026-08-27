<?php

namespace App\Actions\Contracts;

use App\Enums\ChangeSeverity;
use App\Enums\ReconciliationOutcome;

/**
 * Collapses the rows a spreadsheet spends on one contract into a single entry.
 *
 * A sale with two buyers is written as two lines that repeat the contract and
 * differ only in the document. That is the format because it has no ceiling --
 * no "Documento 2", "Documento 3", no list packed into a cell -- but it means
 * the file is no longer one line per contract, and everything downstream still
 * is: the occupancy projection would read two lines for one contract as two
 * contracts fighting over the same unit, and the importer would insert it twice.
 *
 * So the grouping happens here, once, between classification and projection:
 *
 * - lines that name the same contract become one entry carrying the set of
 *   buyers and the numbers of the lines it came from;
 * - the same buyer repeated is counted once, and said so -- it is a warning
 *   about the file, not a defect of the position it describes;
 * - lines that name the same contract but disagree about what the contract *is*
 *   are refused, naming the field and the two lines. Choosing one of them would
 *   be inventing a position nobody wrote.
 *
 * Rows that never got far enough to have an identity -- blank, missing fields,
 * unknown development, unresolvable client -- pass through untouched. They are
 * already errors, and grouping them would only lose their line numbers.
 */
class ContractBuyerGrouping
{
    /**
     * Fields that describe the contract itself, and therefore have to agree
     * across every line that claims to be it.
     *
     * @var array<string, string>
     */
    private const CONTRACT_FIELDS = [
        'construction_id' => 'Empreendimento',
        'construction_unit_id' => 'Unidade',
        'code' => 'Contrato',
        'sale_date' => 'Data da venda',
        'sale_value' => 'Valor da venda',
        'contract_status' => 'Status',
        'cancellation_date' => 'Data do distrato',
    ];

    public function __construct(
        private readonly ContractBuyerReconciler $buyerReconciler = new ContractBuyerReconciler,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, list<int>>  $currentBuyers  contract id => buyer ids on record
     * @param  array<int, string>  $clientNames  client id => name, for messages only
     * @return list<array<string, mixed>>
     */
    public function collapse(array $rows, array $currentBuyers, array $clientNames): array
    {
        $collapsed = [];
        $groups = [];

        foreach ($rows as $row) {
            $key = $this->identityOf($row);

            if ($key === null) {
                $collapsed[] = $row;

                continue;
            }

            $groups[$key][] = $row;
        }

        foreach ($groups as $group) {
            $collapsed[] = $this->collapseGroup($group, $currentBuyers, $clientNames);
        }

        usort($collapsed, fn (array $first, array $second): int => $first['line'] <=> $second['line']);

        return $collapsed;
    }

    /**
     * The identity a row claims, or null when it never resolved one.
     *
     * @param  array<string, mixed>  $row
     */
    private function identityOf(array $row): ?string
    {
        if (($row['construction_id'] === null) || ($row['buyer_id'] === null)) {
            return null;
        }

        return $row['construction_id'].'|'.$row['code_normalized'];
    }

    /**
     * @param  list<array<string, mixed>>  $group
     * @param  array<int, list<int>>  $currentBuyers
     * @param  array<int, string>  $clientNames
     * @return array<string, mixed>
     */
    private function collapseGroup(array $group, array $currentBuyers, array $clientNames): array
    {
        $leader = $group[0];
        $lines = array_map(fn (array $row): int => $row['line'], $group);

        $leader['lines'] = $lines;

        if (count($group) > 1) {
            $divergence = $this->firstDivergence($group);

            if ($divergence !== null) {
                return [
                    ...$leader,
                    'client_ids' => [],
                    'buyer_comparison' => null,
                    'outcome' => ReconciliationOutcome::Conflict,
                    'message' => $divergence,
                ];
            }
        }

        ['ids' => $buyerIds, 'repeated' => $repeated] = $this->buyerSetOf($group);

        $leader['client_ids'] = $buyerIds;
        $leader['client_label'] = $this->describeBuyers($buyerIds, $clientNames);

        $notes = [];

        if ($repeated !== []) {
            $notes[] = $this->describeRepeatedBuyers($repeated, $clientNames);
        }

        /**
         * A contract the file is introducing has no buyer set to compare
         * against: whatever the lines listed is what it will be born with.
         */
        if ($leader['contract_id'] === null) {
            return $this->withNotes($leader, $notes);
        }

        $comparison = $this->buyerReconciler->compare(
            $currentBuyers[$leader['contract_id']] ?? [],
            $buyerIds,
        );

        $leader['buyer_comparison'] = $comparison;

        if ($comparison->isUnchanged()) {
            return $this->withNotes($leader, $notes);
        }

        $notes[] = 'Compradores: '.$comparison->summary($clientNames);

        return $this->withNotes([
            ...$leader,
            'outcome' => $this->worstOutcome($leader['outcome'], $comparison->severity()),
        ], $notes);
    }

    /**
     * The first field two lines of the same contract disagree about, written the
     * way the conference screen should read it.
     *
     * @param  list<array<string, mixed>>  $group
     */
    private function firstDivergence(array $group): ?string
    {
        $leader = $group[0];

        foreach (self::CONTRACT_FIELDS as $field => $label) {
            foreach ($group as $row) {
                if ($this->sameValue($leader[$field] ?? null, $row[$field] ?? null)) {
                    continue;
                }

                return sprintf(
                    'Contrato %s possui valores divergentes na planilha. %s: linha %d = %s, linha %d = %s. Corrija a planilha: linhas do mesmo contrato precisam repetir os mesmos dados contratuais.',
                    $leader['code'],
                    $label,
                    $leader['line'],
                    $this->describeValue($leader[$field] ?? null),
                    $row['line'],
                    $this->describeValue($row[$field] ?? null),
                );
            }
        }

        return null;
    }

    /**
     * The buyers of the group, each counted once, plus the ones that repeated.
     *
     * @param  list<array<string, mixed>>  $group
     * @return array{ids: list<int>, repeated: array<int, list<int>>}
     */
    private function buyerSetOf(array $group): array
    {
        $ids = [];
        $linesByClient = [];

        foreach ($group as $row) {
            $clientId = (int) $row['buyer_id'];

            $linesByClient[$clientId][] = $row['line'];

            if (! in_array($clientId, $ids, true)) {
                $ids[] = $clientId;
            }
        }

        $repeated = array_filter($linesByClient, fn (array $lines): bool => count($lines) > 1);

        return ['ids' => $ids, 'repeated' => $repeated];
    }

    /**
     * @param  array<int, list<int>>  $repeated
     * @param  array<int, string>  $clientNames
     */
    private function describeRepeatedBuyers(array $repeated, array $clientNames): string
    {
        $parts = [];

        foreach ($repeated as $clientId => $lines) {
            $parts[] = sprintf(
                '%s (linhas %s)',
                $clientNames[$clientId] ?? "Cliente #{$clientId}",
                implode(' e ', $lines),
            );
        }

        return 'Comprador repetido na planilha: '.implode(', ', $parts).'. O vínculo será considerado uma única vez.';
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, string>  $clientNames
     */
    private function describeBuyers(array $ids, array $clientNames): string
    {
        return implode(', ', array_map(
            fn (int $id): string => $clientNames[$id] ?? "Cliente #{$id}",
            $ids,
        ));
    }

    /**
     * The worse of what the scalar comparison decided and what the buyer set
     * did. A blocked buyer change refuses a row the fields alone would have
     * accepted, and a critical one lifts a routine update to a critical one.
     */
    private function worstOutcome(ReconciliationOutcome $outcome, ChangeSeverity $severity): ReconciliationOutcome
    {
        if ($outcome->blocksImport()) {
            return $outcome;
        }

        return match ($severity) {
            ChangeSeverity::Blocked => ReconciliationOutcome::Conflict,
            ChangeSeverity::Critical => ReconciliationOutcome::CriticalUpdate,
            ChangeSeverity::Normal => $outcome === ReconciliationOutcome::Unchanged
                ? ReconciliationOutcome::Update
                : $outcome,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private function withNotes(array $row, array $notes): array
    {
        if ($notes === []) {
            return $row;
        }

        $existing = filled($row['message'] ?? null) ? [$row['message']] : [];

        return [...$row, 'message' => implode(' · ', [...$existing, ...$notes])];
    }

    private function sameValue(mixed $first, mixed $second): bool
    {
        if (($first instanceof \BackedEnum) || ($second instanceof \BackedEnum)) {
            return ($first?->value ?? null) === ($second?->value ?? null);
        }

        return $first === $second;
    }

    private function describeValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            $value instanceof \BackedEnum => (string) $value->value,
            is_float($value) => number_format($value, 2, ',', '.'),
            default => (string) $value,
        };
    }
}
