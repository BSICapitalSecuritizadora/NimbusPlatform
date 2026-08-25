<?php

namespace App\Console\Commands;

use App\Models\Nimbus\PortalUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class BackfillNimbusPortalUserPiiHashes extends Command
{
    protected $signature = 'nimbus:backfill-pii-hashes {--dry-run : Report without writing} {--batch=500 : Batch size}';

    protected $description = 'Backfill encrypted PII and blind indexes for nimbus_portal_users (idempotent, reportable).';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $batchSize = (int) $this->option('batch');

        $total = DB::table('nimbus_portal_users')->count();
        $this->info("Total portal users: {$total}");

        $migrated = 0;
        $alreadyOk = 0;
        $conflicts = [];
        $errors = [];
        $hashesSeen = [];

        // Preload existing hashes to detect duplicate normalized identifiers before enforcing uniqueness.
        $existingHashes = DB::table('nimbus_portal_users')
            ->whereNotNull('document_number_hash')
            ->pluck('document_number_hash', 'id')
            ->all();

        foreach ($existingHashes as $id => $hash) {
            $hashesSeen[$hash][] = $id;
        }

        DB::table('nimbus_portal_users')->orderBy('id')->chunk($batchSize, function ($rows) use (&$migrated, &$alreadyOk, &$conflicts, &$errors, &$hashesSeen, $isDryRun): void {
            foreach ($rows as $row) {
                $id = $row->id;

                // Read raw values directly; encrypted cast would attempt decrypt and fail for legacy plaintext.
                $rawDoc = $row->document_number;
                $rawPhone = $row->phone_number;
                $existingDocHash = $row->document_number_hash;
                $existingPhoneHash = $row->phone_number_hash;

                // Attempt to decrypt; if fails, treat as plaintext legacy value.
                $decryptedDoc = $this->tryDecrypt($rawDoc);
                $decryptedPhone = $this->tryDecrypt($rawPhone);

                // If raw was plaintext digits, decrypted will be same as raw (since decrypt failed, we return raw).
                // For already-encrypted rows, decrypted is the original plaintext.
                $plainDoc = $decryptedDoc ?? $rawDoc;
                $plainPhone = $decryptedPhone ?? $rawPhone;

                // Normalize empty strings to null.
                $plainDoc = $plainDoc !== null && trim((string) $plainDoc) === '' ? null : $plainDoc;
                $plainPhone = $plainPhone !== null && trim((string) $plainPhone) === '' ? null : $plainPhone;

                $expectedDocHash = PortalUser::documentNumberHash($plainDoc);
                $expectedPhoneHash = PortalUser::phoneNumberHash($plainPhone);

                $needsUpdate = false;
                $updates = [];

                // Check if document_number needs encryption (raw is plaintext, not encrypted).
                if ($plainDoc !== null && $rawDoc !== null && ! $this->isEncrypted($rawDoc)) {
                    $needsUpdate = true;
                    $updates['document_number'] = $isDryRun ? '[would encrypt]' : Crypt::encryptString((string) $plainDoc);
                }

                if ($plainPhone !== null && $rawPhone !== null && ! $this->isEncrypted($rawPhone)) {
                    $needsUpdate = true;
                    $updates['phone_number'] = $isDryRun ? '[would encrypt]' : Crypt::encryptString((string) $plainPhone);
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

        $this->info("Migrated: {$migrated}, Already OK: {$alreadyOk}, Conflicts: ".count($conflicts).', Errors: '.count($errors));

        if (! empty($conflicts)) {
            $this->table(['id', 'hash', 'conflicts_with'], array_map(fn ($c): array => [$c['id'], substr($c['hash'], 0, 16).'...', implode(',', $c['conflicts_with'])], $conflicts));
        }

        if (! empty($errors)) {
            $this->table(['id', 'error'], array_map(fn ($e): array => [$e['id'], mb_substr($e['error'], 0, 120)], $errors));
        }

        if ($isDryRun) {
            $this->info('Dry-run complete. No writes performed.');
        }

        return count($errors) > 0 || count($conflicts) > 0 ? 1 : 0;
    }

    private function tryDecrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // Not encrypted (legacy plaintext) or invalid.
            return $value;
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
}
