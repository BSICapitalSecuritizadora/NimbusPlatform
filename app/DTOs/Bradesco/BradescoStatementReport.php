<?php

declare(strict_types=1);

namespace App\DTOs\Bradesco;

use App\DTOs\BaseDTO;

/**
 * Statement-response scope inspection.
 *
 * Statement movements are bank-account data, not product balances:
 * this report describes window, counts and section presence, and
 * refuses any reading that would turn movements into application
 * valuations or allocation inputs.
 */
readonly class BradescoStatementReport extends BaseDTO
{
    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @param  list<string>  $completenessIssues
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $bankAccountId,
        public string $maskedAccount,
        public array $sections,
        public int $totalMovements,
        public ?string $windowStart,
        public ?string $windowEnd,
        public bool $complete,
        public array $completenessIssues,
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
            'sections' => $this->sections,
            'total_movements' => $this->totalMovements,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'complete' => $this->complete,
            'completeness_issues' => $this->completenessIssues,
            'warnings' => $this->warnings,
            'requested_at' => $this->requestedAt,
            'captured_at' => $this->capturedAt,
            'read_only' => true,
        ];
    }
}
