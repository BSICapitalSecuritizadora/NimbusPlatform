<?php

declare(strict_types=1);

namespace App\DTOs\Bradesco;

use App\DTOs\BaseDTO;

/**
 * Read-only matching between bank-returned products and registered
 * BankApplications.
 *
 * Every list is advisory: the matcher never creates, deletes or
 * reassigns anything. A product code match is scoped to the single
 * physical account under diagnosis.
 */
readonly class BradescoMatchReport extends BaseDTO
{
    /**
     * @param  list<array<string, mixed>>  $recognized
     * @param  list<array<string, mixed>>  $formatVariants
     * @param  list<array<string, mixed>>  $unregistered
     * @param  list<array<string, mixed>>  $absentFromResponse
     * @param  list<array<string, mixed>>  $ambiguous
     * @param  list<array<string, mixed>>  $missingIdentifiers
     */
    public function __construct(
        public int $bankAccountId,
        public string $maskedAccount,
        public array $recognized,
        public array $formatVariants,
        public array $unregistered,
        public array $absentFromResponse,
        public array $ambiguous,
        public array $missingIdentifiers,
        public string $evaluatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bank_account_id' => $this->bankAccountId,
            'masked_account' => $this->maskedAccount,
            'recognized' => $this->recognized,
            'format_variants' => $this->formatVariants,
            'unregistered' => $this->unregistered,
            'absent_from_response' => $this->absentFromResponse,
            'ambiguous' => $this->ambiguous,
            'missing_identifiers' => $this->missingIdentifiers,
            'evaluated_at' => $this->evaluatedAt,
            'read_only' => true,
        ];
    }
}
