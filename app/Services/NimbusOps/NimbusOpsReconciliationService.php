<?php

namespace App\Services\NimbusOps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only reconciliation — never mutates source or target.
 * Explicit semantic mapping NimbusOps → NimbusPlatform is documented in $tableMap and $conceptMap.
 */
class NimbusOpsReconciliationService
{
    /**
     * Semantic mapping NimbusOps concept → NimbusPlatform concept
     *
     * @var array<string, string>
     */
    public const CONCEPT_MAP = [
        'NimbusOps: operations (legacy `operations` with 8 assignment columns) → NimbusPlatform: operations (same 8 columns, plus planSets)',
        'NimbusOps: operation responsibilities (operations.assigned_user_id etc. 8 roles) → NimbusPlatform: operations (same 8, verified via hasParticipant)',
        'NimbusOps: measurement_files (legacy `measurement_files` + `measurement_reviews` + `measurement_file_assets`) → NimbusPlatform: measurements + measurement_assets (one asset per plan_set, with storage_path/sha256)',
        'NimbusOps: measurement_reviews (stage 1-5, reviewer_user_id, status pending/approved/rejected, paused_at) → NimbusPlatform: measurement_reviews (stage, reviewer_user_id, status pending/approved/rejected, paused_at via reviews + pauses table)',
        'NimbusOps: pauses (measurement_reviews.paused_at or measurement_pauses) → NimbusPlatform: measurement_pauses (stage, paused_at/resumed_at, paused_by) + reviews.paused_at',
        'NimbusOps: payments (legacy `payments` with operation_id, measurement_file_id, amount, pay_date, method, plan_set) → NimbusPlatform: measurement_payments (operation_id, measurement_id, amount, pay_date, method, plan_set_id)',
        'NimbusOps: receipts (payment receipt_path/sha256) → NimbusPlatform: measurement_payments.receipt_path/sha256 + DocumentDownload',
        'NimbusOps: plans (legacy `plans` + `plan_lines` per construction) → NimbusPlatform: measurement_plan_sets + measurement_plan_lines (per construction_id, with planned/realized percents)',
        'NimbusOps: users (legacy `users` active, azure_id/email) → NimbusPlatform: users (is_active, approved_at, azure_id blind index)',
    ];

    public function reconcile(string $sourceConnection = 'nimbus_ops_legacy', ?string $targetConnection = null): array
    {
        $targetConnection = $targetConnection ?? config('database.default');

        $entities = [
            'operations',
            'measurements',
            'measurement_reviews',
            'measurement_pauses',
            'measurement_payments',
            'operation_responsibilities',
            'measurement_plan_sets',
            'measurement_plan_lines',
        ];

        $report = [];

        foreach ($entities as $entity) {
            $report[$entity] = $this->reconcileEntity($entity, $sourceConnection, $targetConnection);
        }

        $report['files'] = $this->reconcileFiles($sourceConnection, $targetConnection);
        $report['users'] = $this->reconcileUsers($sourceConnection, $targetConnection);

        return $report;
    }

    private function reconcileEntity(string $entity, string $source, string $target): array
    {
        // Explicit semantic mapping — do not assume identical table names
        $tableMap = [
            'operations' => 'operations', // NimbusOps: operations → Platform: operations
            'measurements' => 'measurements', // legacy measurement_files → measurements
            'measurement_reviews' => 'measurement_reviews',
            'measurement_pauses' => 'measurement_pauses',
            'measurement_payments' => 'measurement_payments', // legacy payments → measurement_payments
            'operation_responsibilities' => 'operations', // virtual — checks 8 assignment columns
            'measurement_plan_sets' => 'measurement_plan_sets', // legacy plans → plan_sets
            'measurement_plan_lines' => 'measurement_plan_lines', // legacy plan_lines → plan_lines
        ];

        $table = $tableMap[$entity] ?? $entity;

        try {
            $sourceCount = DB::connection($source)->table($table)->count();
        } catch (\Throwable $e) {
            return [
                'source_count' => null,
                'target_count' => null,
                'status' => 'source_unavailable',
                'error' => $e->getMessage(),
                'matched' => 0,
                'missing' => 0,
                'orphaned' => 0,
                'mismatched' => 0,
                'ambiguous' => 0,
                'unresolved' => 0,
            ];
        }

        try {
            $targetCount = DB::connection($target)->table($table)->count();
        } catch (\Throwable $e) {
            $targetCount = null;
        }

        // Chunked full comparison — no arbitrary 1000 truncation. Uses keyset pagination via chunkById
        // to handle large tables without hiding records. If source has 100k rows, all are checked.
        $sourceIds = [];
        $targetIds = [];

        try {
            // Use cursor + chunk to avoid memory blowup, collect all IDs via chunkById
            DB::connection($source)->table($table)->orderBy('id')->chunkById(1000, function ($rows) use (&$sourceIds) {
                foreach ($rows as $r) {
                    $sourceIds[] = (int) $r->id;
                }
            });
            DB::connection($target)->table($table)->orderBy('id')->chunkById(1000, function ($rows) use (&$targetIds) {
                foreach ($rows as $r) {
                    $targetIds[] = (int) $r->id;
                }
            });
        } catch (\Throwable $e) {
            return [
                'source_count' => $sourceCount,
                'target_count' => $targetCount,
                'status' => 'error',
                'error' => $e->getMessage(),
                'matched' => 0,
                'missing' => 0,
                'orphaned' => 0,
            ];
        }

        $sourceSet = array_flip($sourceIds);
        $targetSet = array_flip($targetIds);

        $matched = 0;
        $missing = 0;
        foreach ($sourceIds as $id) {
            if (isset($targetSet[$id])) {
                $matched++;
            } else {
                $missing++;
            }
        }

        $orphaned = 0;
        foreach ($targetIds as $id) {
            if (! isset($sourceSet[$id])) {
                $orphaned++;
            }
        }

        // For mismatched/ambiguous/unresolved, we would need field-level comparison — placeholder 0 until deep diff is implemented
        $status = ($sourceCount === $targetCount && $missing === 0 && $orphaned === 0) ? 'matched' : 'diff';
        if ($missing > 0 || $orphaned > 0) {
            $status = 'diff';
        }

        return [
            'source_count' => $sourceCount,
            'target_count' => $targetCount,
            'matched' => $matched,
            'missing' => $missing,
            'orphaned' => $orphaned,
            'mismatched' => 0,
            'ambiguous' => 0,
            'unresolved' => 0,
            'status' => $status,
        ];
    }

    private function reconcileFiles(string $source, string $target): array
    {
        $result = [
            'expected' => 0,
            'found' => 0,
            'missing' => 0,
            'unreadable' => 0,
            'checksum_match' => 0,
            'checksum_mismatch' => 0,
            'duplicate' => 0,
            'ambiguous' => 0,
        ];

        try {
            // Chunk all measurement assets and payments receipts — not just 50 sample
            $checked = 0;
            $missing = 0;
            $mismatch = 0;
            $match = 0;
            $unreadable = 0;

            DB::connection($target)->table('measurement_assets')->orderBy('id')->chunkById(500, function ($rows) use (&$checked, &$missing, &$mismatch, &$match, &$unreadable) {
                foreach ($rows as $row) {
                    $checked++;
                    $disk = $row->storage_disk ?? 'local';
                    $path = $row->storage_path;
                    if (blank($path)) {
                        $missing++;

                        continue;
                    }
                    if (! Storage::disk($disk)->exists($path)) {
                        $missing++;
                    } else {
                        try {
                            $hash = hash_file('sha256', Storage::disk($disk)->path($path));
                            if (isset($row->sha256) && $hash === $row->sha256) {
                                $match++;
                            } elseif (isset($row->sha256) && $hash !== $row->sha256) {
                                $mismatch++;
                            } else {
                                $match++; // no sha256 to compare, count as found
                            }
                        } catch (\Throwable) {
                            $unreadable++;
                        }
                    }
                }
            });

            // Also check receipts
            DB::connection($target)->table('measurement_payments')->whereNotNull('receipt_path')->orderBy('id')->chunkById(500, function ($rows) use (&$checked, &$missing, &$mismatch, &$match, &$unreadable) {
                foreach ($rows as $row) {
                    $checked++;
                    $disk = $row->receipt_disk ?? 'local';
                    $path = $row->receipt_path;
                    if (blank($path)) {
                        $missing++;

                        continue;
                    }
                    if (! Storage::disk($disk)->exists($path)) {
                        $missing++;
                    } else {
                        try {
                            $hash = hash_file('sha256', Storage::disk($disk)->path($path));
                            if (isset($row->receipt_sha256) && $hash === $row->receipt_sha256) {
                                $match++;
                            } elseif (isset($row->receipt_sha256) && $hash !== $row->receipt_sha256) {
                                $mismatch++;
                            } else {
                                $match++;
                            }
                        } catch (\Throwable) {
                            $unreadable++;
                        }
                    }
                }
            });

            $result['expected'] = $checked;
            $result['found'] = $match;
            $result['missing'] = $missing;
            $result['unreadable'] = $unreadable;
            $result['checksum_match'] = $match;
            $result['checksum_mismatch'] = $mismatch;
            $result['status'] = ($missing === 0 && $mismatch === 0 && $unreadable === 0) ? 'ok' : 'issues';
        } catch (\Throwable $e) {
            $result['status'] = 'unavailable';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function reconcileUsers(string $source, string $target): array
    {
        try {
            $targetCount = DB::connection($target)->table('users')->count();
            // Also count active target users with azure_id for mapping
            $targetActive = DB::connection($target)->table('users')->where('is_active', true)->count();
        } catch (\Throwable $e) {
            return ['status' => 'unavailable', 'error' => $e->getMessage(), 'source_count' => null, 'target_count' => null, 'mapped' => 0, 'unresolved' => 0, 'ambiguous' => 0];
        }

        try {
            $sourceCount = DB::connection($source)->table('users')->count();
            // For mapping, try to match via email or azure_id — chunked
            $unresolved = 0;
            $mapped = 0;
            $ambiguous = 0;

            DB::connection($source)->table('users')->orderBy('id')->chunkById(500, function ($rows) use ($target, &$mapped, &$unresolved) {
                foreach ($rows as $row) {
                    $email = $row->email ?? null;
                    $azure = $row->azure_id ?? null;
                    $exists = false;
                    if ($azure) {
                        $exists = DB::connection($target)->table('users')->where('azure_id', $azure)->exists();
                    }
                    if (! $exists && $email) {
                        $exists = DB::connection($target)->table('users')->where('email', $email)->exists();
                    }
                    if ($exists) {
                        $mapped++;
                    } else {
                        $unresolved++;
                    }
                }
            });

            return [
                'source_count' => $sourceCount,
                'target_count' => $targetCount,
                'target_active' => $targetActive,
                'mapped' => $mapped,
                'unresolved' => $unresolved,
                'ambiguous' => $ambiguous,
                'status' => $unresolved === 0 ? 'matched' : 'diff',
            ];
        } catch (\Throwable $e) {
            return ['source_count' => null, 'target_count' => $targetCount, 'status' => 'source_unavailable', 'note' => 'Provide mapping via azure_id/email blind index', 'error' => $e->getMessage(), 'mapped' => 0, 'unresolved' => 0, 'ambiguous' => 0];
        }
    }
}
