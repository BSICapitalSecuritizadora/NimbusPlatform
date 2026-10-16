<?php

declare(strict_types=1);

namespace App\DTOs\Bradesco;

use App\DTOs\BaseDTO;

/**
 * Normalized balance-response diagnostic.
 *
 * Groups rows by product for inspection WITHOUT totals: the manual
 * never designates which (codigoProduto, identificadorSaldo) row is
 * the authoritative usable balance, so financial mapping stays
 * UNRESOLVED and any summation would invent a consolidated balance.
 */
readonly class BradescoBalanceReport extends BaseDTO
{
    public const MAPPING_UNRESOLVED = 'unresolved';

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @param  list<BradescoBalanceRow>  $rows
     * @param  array<string, list<BradescoBalanceRow>>  $groupedByProduct
     * @param  list<string>  $completenessIssues
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $bankAccountId,
        public string $maskedAccount,
        public ?int $tipoOperacao,
        public array $sections,
        public array $rows,
        public array $groupedByProduct,
        public bool $complete,
        public array $completenessIssues,
        public string $financialMapping,
        public string $financialMappingReason,
        public array $warnings,
        public string $requestedAt,
        public string $capturedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bank_account_id' => $this->bankAccountId,
            'masked_account' => $this->maskedAccount,
            'tipo_operacao' => $this->tipoOperacao,
            'sections' => $this->sections,
            'rows' => array_map(fn (BradescoBalanceRow $row): array => $row->toArray(), $this->rows),
            'grouped_by_product' => array_map(
                fn (array $rows): array => array_map(fn (BradescoBalanceRow $row): array => $row->toArray(), $rows),
                $this->groupedByProduct
            ),
            'complete' => $this->complete,
            'completeness_issues' => $this->completenessIssues,
            'financial_mapping' => $this->financialMapping,
            'financial_mapping_reason' => $this->financialMappingReason,
            'warnings' => $this->warnings,
            'requested_at' => $this->requestedAt,
            'captured_at' => $this->capturedAt,
            'read_only' => true,
        ];
    }
}
