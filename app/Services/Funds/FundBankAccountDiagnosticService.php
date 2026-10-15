<?php

namespace App\Services\Funds;

use App\DTOs\Funds\FundBankAccountDiagnosticReport;
use App\Support\BankAccountNormalizer;
use App\Support\BankIdentifierMasker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only diagnostic that maps legacy funds onto strict canonical
 * bank accounts without writing anything.
 *
 * Every fund is parsed with the Phase 1A {@see BankAccountNormalizer} — the
 * single source of parsing truth — and grouped by its strict canonical key
 * (bank + agency parts + account parts, leading zeros preserved). Nothing is
 * ever merged: zero-padding lookalikes and Conta Azul disagreements are
 * reported as review candidates, never resolved.
 *
 * All reads run under {@see ReadOnlyDiagnosticGuard}, so a future edit that
 * accidentally introduces a write fails loudly instead of mutating data.
 */
class FundBankAccountDiagnosticService
{
    public const STATUS_ACCOUNT_IDENTIFIED = 'ACCOUNT_IDENTIFIED';

    public const STATUS_APPLICATION_PENDING = 'APPLICATION_PENDING';

    public const STATUS_MAPPING_CONFLICT = 'MAPPING_CONFLICT';

    public const STATUS_BLOCKED_INVALID_ACCOUNT = 'BLOCKED_INVALID_ACCOUNT';

    public const REASON_MISSING_BANK = 'missing_bank';

    public const REASON_MISSING_AGENCY = 'missing_agency';

    public const REASON_MISSING_ACCOUNT = 'missing_account';

    public const REASON_MALFORMED_AGENCY = 'malformed_agency';

    public const REASON_MALFORMED_ACCOUNT = 'malformed_account';

    public const REASON_CONTA_AZUL_CONFLICT = 'conta_azul_conflict';

    public const REASON_NUMERIC_MERGE_CANDIDATE = 'numeric_merge_candidate';

    public const REASON_EXISTING_ACCOUNT_MISMATCH = 'existing_account_mismatch';

    public const CONTA_NONE = 'none';

    public const CONTA_SINGLE = 'single';

    public const CONTA_CONFLICT = 'conflict';

    public const CONTA_NOT_APPLICABLE = 'not_applicable';

    /**
     * Diagnose every fund on the given connection.
     *
     * @throws InvalidArgumentException When the chunk size or limit is invalid.
     */
    public function diagnose(
        string $connection,
        int $chunkSize = 500,
        ?int $limit = null,
        string $source = 'synthetic',
        ?string $sourceNote = null
    ): FundBankAccountDiagnosticReport {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size must be at least 1.');
        }

        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('The limit must be at least 1.');
        }

        return ReadOnlyDiagnosticGuard::run($connection, function () use ($connection, $chunkSize, $limit, $source, $sourceNote): FundBankAccountDiagnosticReport {
            $entries = $this->readFundEntries($connection, $chunkSize, $limit);

            $existingAccounts = $this->readExistingAccounts($connection);

            $database = DB::connection($connection);

            return $this->buildReport(
                $connection,
                $database->getDriverName(),
                (string) $database->getDatabaseName(),
                $entries,
                $existingAccounts,
                $chunkSize,
                $limit,
                $source,
                $sourceNote
            );
        });
    }

    /**
     * Read the funds table in id-ordered chunks.
     *
     * @return array{entries: list<array{id: int, emission_id: ?int, bank_id: ?int, agency: ?string, account: ?string, conta_azul_account_id: ?string, bank_account_id: ?int, bank_application_id: ?int}>, truncated: bool}
     */
    private function readFundEntries(string $connection, int $chunkSize, ?int $limit): array
    {
        $entries = [];
        $remaining = $limit;
        $lastId = 0;

        DB::connection($connection)
            ->table('funds')
            ->select([
                'id',
                'emission_id',
                'bank_id',
                'agency',
                'account',
                'conta_azul_account_id',
                'bank_account_id',
                'bank_application_id',
            ])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (&$entries, &$remaining, &$lastId): bool {
                foreach ($rows as $row) {
                    if ($remaining !== null && $remaining <= 0) {
                        return false;
                    }

                    $lastId = (int) $row->id;

                    $entries[] = [
                        'id' => (int) $row->id,
                        'emission_id' => $row->emission_id === null ? null : (int) $row->emission_id,
                        'bank_id' => $row->bank_id === null ? null : (int) $row->bank_id,
                        'agency' => $row->agency === null ? null : (string) $row->agency,
                        'account' => $row->account === null ? null : (string) $row->account,
                        'conta_azul_account_id' => $row->conta_azul_account_id === null ? null : (string) $row->conta_azul_account_id,
                        'bank_account_id' => $row->bank_account_id === null ? null : (int) $row->bank_account_id,
                        'bank_application_id' => $row->bank_application_id === null ? null : (int) $row->bank_application_id,
                    ];

                    if ($remaining !== null) {
                        $remaining--;
                    }
                }

                return $remaining === null || $remaining > 0;
            }, 'id');

        $truncated = false;

        if ($limit !== null && count($entries) >= $limit) {
            $truncated = DB::connection($connection)
                ->table('funds')
                ->where('id', '>', $lastId)
                ->exists();
        }

        return ['entries' => $entries, 'truncated' => $truncated];
    }

    /**
     * Index existing bank accounts by strict canonical key.
     *
     * @return array<string, array{id: int, conta_azul_account_id: ?string}>
     */
    private function readExistingAccounts(string $connection): array
    {
        $accounts = [];

        DB::connection($connection)
            ->table('bank_accounts')
            ->select([
                'id',
                'bank_id',
                'agency_number',
                'agency_digit',
                'account_number',
                'account_digit',
                'conta_azul_account_id',
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$accounts): void {
                foreach ($rows as $row) {
                    $key = BankAccountNormalizer::canonicalKey(
                        (int) $row->bank_id,
                        (string) $row->agency_number,
                        (string) $row->agency_digit,
                        (string) $row->account_number,
                        (string) $row->account_digit
                    );

                    $accounts[$key] = [
                        'id' => (int) $row->id,
                        'conta_azul_account_id' => $row->conta_azul_account_id === null ? null : (string) $row->conta_azul_account_id,
                    ];
                }
            }, 'id');

        return $accounts;
    }

    /**
     * @param  array{entries: list<array{id: int, emission_id: ?int, bank_id: ?int, agency: ?string, account: ?string, conta_azul_account_id: ?string, bank_account_id: ?int, bank_application_id: ?int}>, truncated: bool}  $fundData
     * @param  array<string, array{id: int, conta_azul_account_id: ?string}>  $existingAccounts
     */
    private function buildReport(
        string $connection,
        string $driver,
        string $database,
        array $fundData,
        array $existingAccounts,
        int $chunkSize,
        ?int $limit,
        string $source,
        ?string $sourceNote
    ): FundBankAccountDiagnosticReport {
        $parsed = array_map(
            fn (array $entry): array => [...$entry, ...$this->parseEntry($entry)],
            $fundData['entries']
        );

        /** @var array<string, list<int>> $strictGroups canonical key => indexes into $parsed */
        $strictGroups = [];

        foreach ($parsed as $index => $entry) {
            if ($entry['canonical_key'] === null) {
                continue;
            }

            $strictGroups[$entry['canonical_key']][] = $index;
        }

        ksort($strictGroups);

        /** @var array<string, list<string>> $numericGroups numeric key => strict keys */
        $numericGroups = [];

        foreach (array_keys($strictGroups) as $strictKey) {
            $numericGroups[$this->numericKeyForStrictKey($strictKey)][] = $strictKey;
        }

        ksort($numericGroups);

        $candidateStrictKeys = [];

        foreach ($numericGroups as $strictKeys) {
            if (count(array_unique($strictKeys)) > 1) {
                foreach ($strictKeys as $strictKey) {
                    $candidateStrictKeys[$strictKey] = true;
                }
            }
        }

        $preview = [];
        $contaConflicts = [];
        $numericConflicts = [];
        $blocked = [];
        $spellingVariants = [];
        $existingMismatches = [];

        $missing = 0;
        $malformed = 0;
        $reviewRequired = 0;
        $unmappable = 0;
        $alreadyLinked = 0;
        $existingMatches = 0;
        $statusCounts = [
            self::STATUS_ACCOUNT_IDENTIFIED => 0,
            self::STATUS_APPLICATION_PENDING => 0,
            self::STATUS_MAPPING_CONFLICT => 0,
            self::STATUS_BLOCKED_INVALID_ACCOUNT => 0,
        ];

        foreach ($parsed as $index => $entry) {
            if ($entry['bank_account_id'] !== null) {
                $alreadyLinked++;
            }

            if ($entry['canonical_key'] === null) {
                $unmappable++;
                $statusCounts[self::STATUS_BLOCKED_INVALID_ACCOUNT]++;

                if (in_array($entry['failure'], [self::REASON_MISSING_BANK, self::REASON_MISSING_AGENCY, self::REASON_MISSING_ACCOUNT], true)) {
                    $missing++;
                } else {
                    $malformed++;
                }

                $blocked[] = [
                    'fund_id' => $entry['id'],
                    'emission_id' => $entry['emission_id'],
                    'reason' => $entry['failure'],
                    'masked_agency' => BankIdentifierMasker::maskRaw($entry['agency']),
                    'masked_account' => BankIdentifierMasker::maskRaw($entry['account']),
                ];

                $preview[] = [
                    'fund_id' => $entry['id'],
                    'emission_id' => $entry['emission_id'],
                    'bank_id' => $entry['bank_id'],
                    'status' => self::STATUS_BLOCKED_INVALID_ACCOUNT,
                    'reasons' => [$entry['failure']],
                    'masked_agency' => BankIdentifierMasker::maskRaw($entry['agency']),
                    'masked_account' => BankIdentifierMasker::maskRaw($entry['account']),
                    'canonical_group' => null,
                    'group_fund_count' => 0,
                    'group_emission_count' => 0,
                    'shared_by_funds' => false,
                    'shared_by_emissions' => false,
                    'conta_azul_status' => self::CONTA_NOT_APPLICABLE,
                    'existing_bank_account_id' => null,
                    'linked_bank_account_id' => $entry['bank_account_id'],
                    'application_resolved' => $entry['bank_application_id'] !== null,
                    'eligible_for_auto_mapping' => false,
                    'must_skip' => true,
                ];

                continue;
            }

            $strictKey = $entry['canonical_key'];
            $memberIndexes = $strictGroups[$strictKey];
            $emissionIds = [];

            foreach ($memberIndexes as $memberIndex) {
                if ($parsed[$memberIndex]['emission_id'] !== null) {
                    $emissionIds[$parsed[$memberIndex]['emission_id']] = true;
                }
            }

            $contaIds = $this->distinctContaAzulIds($parsed, $memberIndexes);
            $contaStatus = $this->contaAzulStatus($contaIds);
            $existing = $existingAccounts[$strictKey] ?? null;

            $reasons = [];

            if ($contaStatus === self::CONTA_CONFLICT) {
                $reasons[] = self::REASON_CONTA_AZUL_CONFLICT;
            }

            if (isset($candidateStrictKeys[$strictKey])) {
                $reasons[] = self::REASON_NUMERIC_MERGE_CANDIDATE;
            }

            if ($existing !== null && $this->isExistingMismatch($existing, $contaIds)) {
                $reasons[] = self::REASON_EXISTING_ACCOUNT_MISMATCH;
            }

            if ($reasons !== []) {
                $status = self::STATUS_MAPPING_CONFLICT;
                $reviewRequired++;
            } elseif ($entry['bank_account_id'] !== null || $existing !== null) {
                $status = self::STATUS_ACCOUNT_IDENTIFIED;
            } else {
                $status = self::STATUS_APPLICATION_PENDING;
            }

            $statusCounts[$status]++;

            if ($existing !== null) {
                $existingMatches++;
            }

            $sharedByFunds = count($memberIndexes) > 1;
            $sharedByEmissions = count($emissionIds) > 1;

            $preview[] = [
                'fund_id' => $entry['id'],
                'emission_id' => $entry['emission_id'],
                'bank_id' => $entry['bank_id'],
                'status' => $status,
                'reasons' => $reasons,
                'masked_agency' => BankIdentifierMasker::maskAgency($entry['agency_number'], $entry['agency_digit']),
                'masked_account' => BankIdentifierMasker::maskAccount($entry['account_number'], $entry['account_digit']),
                'canonical_group' => $entry['masked_key'],
                'group_fund_count' => count($memberIndexes),
                'group_emission_count' => count($emissionIds),
                'shared_by_funds' => $sharedByFunds,
                'shared_by_emissions' => $sharedByEmissions,
                'conta_azul_status' => $contaStatus,
                'existing_bank_account_id' => $existing['id'] ?? null,
                'linked_bank_account_id' => $entry['bank_account_id'],
                'application_resolved' => $entry['bank_application_id'] !== null,
                'eligible_for_auto_mapping' => $status === self::STATUS_ACCOUNT_IDENTIFIED || $status === self::STATUS_APPLICATION_PENDING,
                'must_skip' => $status === self::STATUS_MAPPING_CONFLICT || $status === self::STATUS_BLOCKED_INVALID_ACCOUNT,
            ];
        }

        $multiFund = 0;
        $multiEmission = 0;
        $contaConflictGroups = 0;
        $spellingVariantGroups = 0;

        foreach ($strictGroups as $strictKey => $memberIndexes) {
            $emissionIds = [];
            $rawSpellings = [];

            foreach ($memberIndexes as $memberIndex) {
                if ($parsed[$memberIndex]['emission_id'] !== null) {
                    $emissionIds[$parsed[$memberIndex]['emission_id']] = true;
                }

                $rawSpellings[$parsed[$memberIndex]['agency']."\0".$parsed[$memberIndex]['account']] = true;
            }

            if (count($memberIndexes) > 1) {
                $multiFund++;
            }

            if (count($emissionIds) > 1) {
                $multiEmission++;
            }

            if (count($rawSpellings) > 1) {
                $spellingVariantGroups++;

                $spellingVariants[] = [
                    'canonical_group' => $parsed[$memberIndexes[0]]['masked_key'],
                    'fund_ids' => array_map(fn (int $memberIndex): int => $parsed[$memberIndex]['id'], $memberIndexes),
                    'variant_count' => count($rawSpellings),
                ];
            }

            $contaIds = $this->distinctContaAzulIds($parsed, $memberIndexes);

            if ($this->contaAzulStatus($contaIds) === self::CONTA_CONFLICT) {
                $contaConflictGroups++;

                $contaConflicts[] = [
                    'canonical_group' => $parsed[$memberIndexes[0]]['masked_key'],
                    'fund_ids' => array_map(fn (int $memberIndex): int => $parsed[$memberIndex]['id'], $memberIndexes),
                    'emission_ids' => array_keys($emissionIds),
                    'masked_conta_azul_ids' => array_map(
                        fn (string $id): string => BankIdentifierMasker::maskContaAzulId($id),
                        $contaIds
                    ),
                ];
            }

            $existing = $existingAccounts[$strictKey] ?? null;

            if ($existing !== null && $this->isExistingMismatch($existing, $contaIds)) {
                $existingMismatches[] = [
                    'canonical_group' => $parsed[$memberIndexes[0]]['masked_key'],
                    'existing_bank_account_id' => $existing['id'],
                    'masked_existing_conta_azul_id' => BankIdentifierMasker::maskContaAzulId($existing['conta_azul_account_id']),
                    'masked_group_conta_azul_ids' => array_map(
                        fn (string $id): string => BankIdentifierMasker::maskContaAzulId($id),
                        $contaIds
                    ),
                    'fund_ids' => array_map(fn (int $memberIndex): int => $parsed[$memberIndex]['id'], $memberIndexes),
                ];
            }
        }

        $collapsible = 0;

        foreach ($numericGroups as $numericKey => $strictKeys) {
            $unique = array_values(array_unique($strictKeys));

            if (count($unique) <= 1) {
                continue;
            }

            sort($unique);

            $collapsible += count($unique) - 1;

            $fundIds = [];

            foreach ($unique as $strictKey) {
                foreach ($strictGroups[$strictKey] as $memberIndex) {
                    $fundIds[] = $parsed[$memberIndex]['id'];
                }
            }

            sort($fundIds);

            $numericConflicts[] = [
                'numeric_group' => $this->maskedNumericKey($numericKey),
                'strict_groups' => array_map(
                    fn (string $strictKey): string => $parsed[$strictGroups[$strictKey][0]]['masked_key'],
                    $unique
                ),
                'fund_ids' => $fundIds,
            ];
        }

        usort($blocked, fn (array $a, array $b): int => $a['fund_id'] <=> $b['fund_id']);

        $totals = [
            'total_funds' => count($parsed),
            'parseable_funds' => count($parsed) - $missing - $malformed,
            'missing_identifier_funds' => $missing,
            'malformed_identifier_funds' => $malformed,
            'distinct_strict_accounts' => count($strictGroups),
            'multi_fund_accounts' => $multiFund,
            'multi_emission_accounts' => $multiEmission,
            'conta_azul_conflict_groups' => $contaConflictGroups,
            'zero_padding_candidate_groups' => count($candidateStrictKeys),
            'zero_padding_collapsible_groups' => $collapsible,
            'review_required_funds' => $reviewRequired,
            'unmappable_funds' => $unmappable,
            'already_linked_funds' => $alreadyLinked,
            'existing_match_funds' => $existingMatches,
            'spelling_variant_groups' => $spellingVariantGroups,
            'existing_mismatch_groups' => count($existingMismatches),
            'status_account_identified' => $statusCounts[self::STATUS_ACCOUNT_IDENTIFIED],
            'status_application_pending' => $statusCounts[self::STATUS_APPLICATION_PENDING],
            'status_mapping_conflict' => $statusCounts[self::STATUS_MAPPING_CONFLICT],
            'status_blocked_invalid_account' => $statusCounts[self::STATUS_BLOCKED_INVALID_ACCOUNT],
        ];

        return new FundBankAccountDiagnosticReport(
            connection: $connection,
            driver: $driver,
            database: $database,
            source: $source,
            sourceNote: $sourceNote,
            chunkSize: $chunkSize,
            limit: $limit,
            truncated: $fundData['truncated'],
            generatedAt: now()->toIso8601String(),
            totals: $totals,
            conflicts: [
                'conta_azul' => $contaConflicts,
                'numeric_candidates' => $numericConflicts,
                'blocked' => $blocked,
                'spelling_variants' => $spellingVariants,
                'existing_mismatches' => $existingMismatches,
            ],
            preview: $preview,
        );
    }

    /**
     * Parse one fund's legacy identifiers with the Phase 1A normalizer.
     *
     * @param  array{id: int, emission_id: ?int, bank_id: ?int, agency: ?string, account: ?string, conta_azul_account_id: ?string, bank_account_id: ?int, bank_application_id: ?int}  $entry
     * @return array{canonical_key: ?string, masked_key: ?string, agency_number: ?string, agency_digit: ?string, account_number: ?string, account_digit: ?string, failure: ?string}
     */
    private function parseEntry(array $entry): array
    {
        $blank = static fn (?string $value): bool => $value === null || trim($value) === '';

        if ($entry['bank_id'] === null) {
            return $this->failedParse(self::REASON_MISSING_BANK);
        }

        if ($blank($entry['agency'])) {
            return $this->failedParse(self::REASON_MISSING_AGENCY);
        }

        if ($blank($entry['account'])) {
            return $this->failedParse(self::REASON_MISSING_ACCOUNT);
        }

        try {
            $agency = BankAccountNormalizer::parseAgency($entry['agency']);
        } catch (InvalidArgumentException) {
            return $this->failedParse(self::REASON_MALFORMED_AGENCY);
        }

        try {
            $account = BankAccountNormalizer::parseAccount($entry['account']);
        } catch (InvalidArgumentException) {
            return $this->failedParse(self::REASON_MALFORMED_ACCOUNT);
        }

        return [
            'canonical_key' => BankAccountNormalizer::canonicalKey(
                $entry['bank_id'],
                $agency['number'],
                $agency['digit'],
                $account['number'],
                $account['digit']
            ),
            'masked_key' => BankIdentifierMasker::maskedCanonicalKey(
                $entry['bank_id'],
                $agency['number'],
                $agency['digit'],
                $account['number'],
                $account['digit']
            ),
            'agency_number' => $agency['number'],
            'agency_digit' => $agency['digit'],
            'account_number' => $account['number'],
            'account_digit' => $account['digit'],
            'failure' => null,
        ];
    }

    /**
     * @return array{canonical_key: null, masked_key: null, agency_number: null, agency_digit: null, account_number: null, account_digit: null, failure: string}
     */
    private function failedParse(string $reason): array
    {
        return [
            'canonical_key' => null,
            'masked_key' => null,
            'agency_number' => null,
            'agency_digit' => null,
            'account_number' => null,
            'account_digit' => null,
            'failure' => $reason,
        ];
    }

    /**
     * Derive the numeric-identity key from a strict canonical key.
     *
     * Leading zeros are stripped from both numbers, but the bank id, both
     * check digits and the agency number itself stay in the key: numeric
     * grouping may only ever suggest that "12345-6" and "0012345-6" within
     * the same bank, agency and digits deserve a human look. It is computed
     * from already-parsed canonical parts, never by re-parsing raw input.
     */
    private function numericKeyForStrictKey(string $strictKey): string
    {
        [$bankId, $agencyNumber, $agencyDigit, $accountNumber, $accountDigit] = explode(':', $strictKey);

        return implode(':', [
            $bankId,
            self::stripLeadingZeros($agencyNumber),
            $agencyDigit,
            self::stripLeadingZeros($accountNumber),
            $accountDigit,
        ]);
    }

    private function maskedNumericKey(string $numericKey): string
    {
        [$bankId, $agencyNumber, $agencyDigit, $accountNumber, $accountDigit] = explode(':', $numericKey);

        return BankIdentifierMasker::maskedCanonicalKey(
            (int) $bankId,
            $agencyNumber,
            $agencyDigit,
            $accountNumber,
            $accountDigit
        );
    }

    private static function stripLeadingZeros(string $number): string
    {
        $stripped = ltrim($number, '0');

        return $stripped === '' ? '0' : $stripped;
    }

    /**
     * @param  list<array{id: int, emission_id: ?int, bank_id: ?int, agency: ?string, account: ?string, conta_azul_account_id: ?string, bank_account_id: ?int, bank_application_id: ?int, canonical_key: ?string}>  $parsed
     * @param  list<int>  $memberIndexes
     * @return list<string> Distinct non-blank Conta Azul ids, sorted.
     */
    private function distinctContaAzulIds(array $parsed, array $memberIndexes): array
    {
        $ids = [];

        foreach ($memberIndexes as $memberIndex) {
            $id = $parsed[$memberIndex]['conta_azul_account_id'];

            if ($id !== null && trim($id) !== '') {
                $ids[trim($id)] = true;
            }
        }

        $distinct = array_keys($ids);

        sort($distinct);

        return $distinct;
    }

    /**
     * @param  list<string>  $distinctIds
     */
    private function contaAzulStatus(array $distinctIds): string
    {
        if ($distinctIds === []) {
            return self::CONTA_NONE;
        }

        if (count($distinctIds) === 1) {
            return self::CONTA_SINGLE;
        }

        return self::CONTA_CONFLICT;
    }

    /**
     * An existing account row only contradicts the legacy group when both
     * sides name an id and they disagree. A null on either side is missing
     * information, not a conflict, and the diagnostic never picks a winner.
     *
     * @param  array{id: int, conta_azul_account_id: ?string}  $existing
     * @param  list<string>  $groupIds
     */
    private function isExistingMismatch(array $existing, array $groupIds): bool
    {
        if ($existing['conta_azul_account_id'] === null || trim($existing['conta_azul_account_id']) === '') {
            return false;
        }

        if ($groupIds === []) {
            return false;
        }

        return ! in_array(trim($existing['conta_azul_account_id']), $groupIds, true);
    }
}
