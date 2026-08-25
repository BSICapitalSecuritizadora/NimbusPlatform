<?php

namespace App\Console\Commands;

use App\Services\Security\BlindIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class BackfillNimbusPortalUserPiiHashes extends Command
{
    protected $signature = 'nimbus:backfill-pii-hashes {--dry-run : Report without writing} {--verify : Verification-only mode (no writes, detailed report)} {--batch=500 : Batch size}';

    protected $description = 'Backfill encrypted PII and blind indexes for nimbus_portal_users (idempotent, reportable). Handles legacy plaintext and stale APP_KEY-derived hashes.';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isVerify = (bool) $this->option('verify');
        $batchSize = (int) $this->option('batch');

        if ($isVerify) {
            $isDryRun = true;
        }

        $total = DB::table('nimbus_portal_users')->count();
        $this->info("Total portal users: {$total}");

        $migrated = 0;
        $alreadyOk = 0;
        $conflicts = [];
        $errors = [];
        $hashesSeen = [];

        // Verification counters
        $encryptedCurrent = 0;
        $legacyPlaintext = 0;
        $staleHash = 0;
        $missingHash = 0;
        $currentHash = 0;
        $corrupted = 0;

        // Preload existing hashes to detect duplicate normalized identifiers before enforcing uniqueness.
        $existingHashes = DB::table('nimbus_portal_users')
            ->whereNotNull('document_number_hash')
            ->pluck('document_number_hash', 'id')
            ->all();

        foreach ($existingHashes as $id => $hash) {
            $hashesSeen[$hash][] = $id;
        }

        DB::table('nimbus_portal_users')->orderBy('id')->chunk($batchSize, function ($rows) use (&$migrated, &$alreadyOk, &$conflicts, &$errors, &$hashesSeen, $isDryRun, $isVerify, &$encryptedCurrent, &$legacyPlaintext, &$staleHash, &$missingHash, &$currentHash, &$corrupted): void {
            foreach ($rows as $row) {
                $id = $row->id;

                // Read raw values directly; encrypted cast would attempt decrypt and fail for legacy plaintext.
                $rawDoc = $row->document_number;
                $rawPhone = $row->phone_number;
                $existingDocHash = $row->document_number_hash;
                $existingPhoneHash = $row->phone_number_hash;

                // Verification: classify raw values
                if ($isVerify) {
                    $this->classifyForVerify($rawDoc, $rawPhone, $existingDocHash, $existingPhoneHash, $encryptedCurrent, $legacyPlaintext, $staleHash, $missingHash, $currentHash, $corrupted);
                }

                // Decrypt or treat as legacy plaintext; handle corrupted ciphertext.
                $plainDoc = $this->resolvePlaintext('document_number', $rawDoc, $id, $errors, $corrupted);
                $plainPhone = $this->resolvePlaintext('phone_number', $rawPhone, $id, $errors, $corrupted);

                // If corrupted, skip this row (error already recorded)
                if ($plainDoc === false || $plainPhone === false) {
                    continue;
                }

                // Normalize empty strings to null.
                $plainDoc = $plainDoc !== null && trim((string) $plainDoc) === '' ? null : $plainDoc;
                $plainPhone = $plainPhone !== null && trim((string) $plainPhone) === '' ? null : $plainPhone;

                $expectedDocHash = $plainDoc !== null ? BlindIndexService::documentNumber($plainDoc) : null;
                $expectedPhoneHash = $plainPhone !== null ? BlindIndexService::phoneNumber($plainPhone) : null;

                // Detect stale: existing hash was computed with old APP_KEY-derived key.
                // If expected != existing, it's either missing, stale, or legacy plaintext case.
                $needsUpdate = false;
                $updates = [];

                // Check if document_number needs encryption (raw is plaintext, not encrypted).
                if ($plainDoc !== null && $rawDoc !== null && ! $this->isEncrypted($rawDoc)) {
                    $needsUpdate = true;
                    $updates['document_number'] = $isDryRun ? '[would encrypt]' : Crypt::encryptString((string) $plainDoc);
                } elseif ($plainDoc === null && $rawDoc !== null) {
                    // Plaintext was empty -> null
                    $needsUpdate = true;
                    $updates['document_number'] = null;
                }

                if ($plainPhone !== null && $rawPhone !== null && ! $this->isEncrypted($rawPhone)) {
                    $needsUpdate = true;
                    $updates['phone_number'] = $isDryRun ? '[would encrypt]' : Crypt::encryptString((string) $plainPhone);
                } elseif ($plainPhone === null && $rawPhone !== null) {
                    $needsUpdate = true;
                    $updates['phone_number'] = null;
                }

                if ($expectedDocHash !== $existingDocHash) {
                    // Detect duplicate normalized document before write.
                    if ($expectedDocHash !== null && isset($hashesSeen[$expectedDocHash])) {
                        $otherIds = array_filter($hashesSeen[$expectedDocHash], fn ($otherId): bool => $otherId !== $id);

                        if (! empty($otherIds)) {
                            $conflicts[] = [
                                'id' => $id,
                                'hash' => $expectedDocHash,
                                'conflicts_with' => $otherIds,
                            ];
                            // Do not write conflicting hash; report and skip this record's doc hash.
                            $this->warn("Conflict: portal_user {$id} document hash collides with ".implode(',', $otherIds));
                            goto phoneHash;
                        }
                    }

                    // Also check for stale legacy APP_KEY-derived hash that would still collide
                    if ($expectedDocHash !== null) {
                        $legacyHash = BlindIndexService::legacyDocumentNumber($plainDoc);
                        if ($legacyHash !== null && $legacyHash !== $expectedDocHash && isset($hashesSeen[$legacyHash])) {
                            $otherIds = array_filter($hashesSeen[$legacyHash], fn ($otherId): bool => $otherId !== $id);
                            if (! empty($otherIds)) {
                                $conflicts[] = [
                                    'id' => $id,
                                    'hash' => $expectedDocHash,
                                    'conflicts_with' => $otherIds,
                                    'note' => 'legacy hash collision',
                                ];
                                $this->warn("Conflict (legacy hash): portal_user {$id} collides with ".implode(',', $otherIds));
                                goto phoneHash;
                            }
                        }
                    }

                    $needsUpdate = true;
                    $updates['document_number_hash'] = $expectedDocHash;

                    if (! $isDryRun && $expectedDocHash !== null) {
                        $hashesSeen[$expectedDocHash][] = $id;
                    }
                }

                phoneHash:
                if ($expectedPhoneHash !== $existingPhoneHash) {
                    $needsUpdate = true;
                    $updates['phone_number_hash'] = $expectedPhoneHash;
                }

                if (! $needsUpdate) {
                    $alreadyOk++;

                    continue;
                }

                if ($isDryRun) {
                    $migrated++;
                    $this->line("Would update portal_user {$id}: ".json_encode($updates));

                    continue;
                }

                try {
                    DB::table('nimbus_portal_users')->where('id', $id)->update($updates);
                    $migrated++;
                } catch (\Throwable $e) {
                    $errors[] = ['id' => $id, 'error' => $e->getMessage()];
                    $this->error("Failed portal_user {$id}: ".$e->getMessage());
                }
            }
        });

        if ($isVerify) {
            $this->info('Verification report:');
            $this->table(['Metric', 'Count'], [
                ['Total rows', $total],
                ['Encrypted current', $encryptedCurrent],
                ['Legacy plaintext', $legacyPlaintext],
                ['Current blind index', $currentHash],
                ['Stale blind index (old APP_KEY)', $staleHash],
                ['Missing hash', $missingHash],
                ['Corrupted ciphertext', $corrupted],
                ['To be migrated', $migrated],
                ['Already OK', $alreadyOk],
                ['Conflicts', count($conflicts)],
                ['Errors', count($errors)],
            ]);
            $this->info('Removal gate: LegacyEncrypted + NULL-hash scan may be removed only when legacyPlaintext=0, staleHash=0, missingHash=0, conflicts=0, errors=0, corrupted=0.');
        } else {
            $this->info("Migrated: {$migrated}, Already OK: {$alreadyOk}, Conflicts: ".count($conflicts).', Errors: '.count($errors));
        }

        if (! empty($conflicts)) {
            $this->table(['id', 'hash', 'conflicts_with'], array_map(fn ($c): array => [$c['id'], substr($c['hash'], 0, 16).'...', implode(',', (array) ($c['conflicts_with'] ?? []))], $conflicts));
        }

        if (! empty($errors)) {
            $this->table(['id', 'error'], array_map(fn ($e): array => [$e['id'], mb_substr($e['error'], 0, 120)], $errors));
        }

        if ($isDryRun && ! $isVerify) {
            $this->info('Dry-run complete. No writes performed.');
        }

        return count($errors) > 0 || count($conflicts) > 0 ? 1 : 0;
    }

    private function classifyForVerify(?string $rawDoc, ?string $rawPhone, ?string $existingDocHash, ?string $existingPhoneHash, int &$encryptedCurrent, int &$legacyPlaintext, int &$staleHash, int &$missingHash, int &$currentHash, int &$corrupted): void
    {
        foreach (['document_number' => [$rawDoc, $existingDocHash], 'phone_number' => [$rawPhone, $existingPhoneHash]] as $field => [$raw, $hash]) {
            if ($raw === null || $raw === '') {
                continue;
            }

            if ($this->isEncrypted($raw)) {
                $encryptedCurrent++;
            } elseif ($this->looksLikeLegacyPlaintext($field, $raw)) {
                $legacyPlaintext++;
            } elseif ($this->looksLikeCiphertext($raw)) {
                $corrupted++;
            }

            if ($raw !== null && $raw !== '' && $hash === null) {
                $missingHash++;
            } elseif ($hash !== null) {
                $plain = $this->tryDecryptForVerify($raw);
                if ($plain !== null) {
                    $expected = $field === 'document_number' ? BlindIndexService::documentNumber($plain) : BlindIndexService::phoneNumber($plain);
                    if ($expected === $hash) {
                        $currentHash++;
                    } else {
                        $staleHash++;
                    }
                }
            }
        }
    }

    private function tryDecryptForVerify(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            if ($this->looksLikeLegacyPlaintext('document_number', $value) || $this->looksLikeLegacyPlaintext('phone_number', $value)) {
                return $value;
            }

            return null;
        }
    }

    /**
     * Resolve plaintext for a field, handling corrupted ciphertext safely.
     * Returns plaintext string|null, or false on corruption (error recorded).
     */
    private function resolvePlaintext(string $key, ?string $raw, int $id, array &$errors, int &$corrupted): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable $e) {
            if ($this->looksLikeCiphertext($raw)) {
                $errors[] = ['id' => $id, 'error' => "Corrupted ciphertext for {$key}"];
                $corrupted++;
                $this->error("Corrupted ciphertext for portal_user {$id} field {$key} — not re-encrypting, requires manual review.");

                return false;
            }

            if ($this->looksLikeLegacyPlaintext($key, $raw)) {
                return $raw;
            }

            $errors[] = ['id' => $id, 'error' => "Malformed value for {$key}: not ciphertext nor legacy format"];
            $this->error("Malformed value for portal_user {$id} field {$key} — skipping.");

            return false;
        }
    }

    private function isEncrypted(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function looksLikeCiphertext(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        $data = json_decode($decoded, true);
        if (! is_array($data)) {
            return false;
        }

        return isset($data['iv'], $data['value'], $data['mac']);
    }

    private function looksLikeLegacyPlaintext(string $key, string $value): bool
    {
        $trimmed = trim($value);
        if ($key === 'document_number') {
            if (! preg_match('/^[\d.\-\s]+$/', $trimmed)) {
                return false;
            }
            $digits = preg_replace('/\D+/', '', $trimmed);

            return $digits !== '' && strlen($digits) === 11;
        }
        if ($key === 'phone_number') {
            if (! preg_match('/^[\d\(\)\-\s]+$/', $trimmed)) {
                return false;
            }
            $digits = preg_replace('/\D+/', '', $trimmed);

            return $digits !== '' && (strlen($digits) === 10 || strlen($digits) === 11);
        }

        return false;
    }
}
