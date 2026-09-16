<?php

namespace App\Console\Commands;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementFileMigration;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Services\DocumentStorageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SecureLegacyMeasurementFiles extends Command
{
    protected $signature = 'measurements:secure-legacy-files
        {--execute : Copia, verifica, atualiza o banco e remove a cópia pública}
        {--limit=0 : Limite total de arquivos; zero processa todos}';

    protected $description = 'Migra arquivos legados de medições do disco público para o armazenamento privado (dry-run por padrão)';

    /**
     * @var array<string, array{model: class-string<Model>, path: string, disk: string, hash: string, mime: string, size: string}>
     */
    private const FILE_CONFIGURATIONS = [
        'files' => [
            'model' => Measurement::class,
            'path' => 'storage_path',
            'disk' => 'storage_disk',
            'hash' => 'sha256',
            'mime' => 'mime_type',
            'size' => 'file_size',
        ],
        'assets' => [
            'model' => MeasurementAsset::class,
            'path' => 'storage_path',
            'disk' => 'storage_disk',
            'hash' => 'sha256',
            'mime' => 'mime_type',
            'size' => 'size',
        ],
        'receipts' => [
            'model' => MeasurementPayment::class,
            'path' => 'receipt_path',
            'disk' => 'receipt_disk',
            'hash' => 'receipt_sha256',
            'mime' => 'receipt_mime_type',
            'size' => 'receipt_size',
        ],
    ];

    private int $processed = 0;

    private int $migrated = 0;

    private int $residues = 0;

    private int $sharedRetained = 0;

    private int $failed = 0;

    private int $skipped = 0;

    private int $alreadySecure = 0;

    private int $recovered = 0;

    public function handle(DocumentStorageService $storage): int
    {
        $this->resetCounters();
        $targetDisk = DocumentStorageService::privateDisk();

        if (! $storage->isAllowedMeasurementWriteDisk($targetDisk)) {
            $this->components->error('O disco privado configurado não é permitido para novos arquivos de medição.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $limit = max(0, (int) $this->option('limit'));
        $this->components->info($execute ? 'Executando migração segura.' : 'Dry-run: nenhum arquivo será copiado ou removido.');

        $this->recoverTrackedMigrations($storage, $execute, $limit);

        foreach (self::FILE_CONFIGURATIONS as $role => $configuration) {
            if ($this->limitReached($limit)) {
                break;
            }

            $this->processNewLegacyRecords($role, $configuration, $storage, $targetDisk, $execute, $limit);
        }

        $this->reconcileRetainedSharedSources($storage, $execute);

        if (! $this->limitReached($limit)) {
            $this->detectUnownedLegacyResidues($storage, $targetDisk, $limit);
        }

        $this->table(
            ['Processados', 'Migrados', 'Origem compartilhada preservada', 'Com resíduo público', 'Recuperados', 'Já seguros', 'Ignorados', 'Falhas'],
            [[$this->processed, $this->migrated, $this->sharedRetained, $this->residues, $this->recovered, $this->alreadySecure, $this->skipped, $this->failed]],
        );

        return $this->failed === 0 && $this->residues === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function recoverTrackedMigrations(DocumentStorageService $storage, bool $execute, int $limit): void
    {
        MeasurementFileMigration::query()
            ->where('state', '!=', MeasurementFileMigration::STATE_SHARED_SOURCE_RETAINED)
            ->orderBy('id')
            ->chunkById(100, function ($journals) use ($storage, $execute, $limit): bool {
                foreach ($journals as $journal) {
                    if ($this->limitReached($limit)) {
                        return false;
                    }

                    $this->processed++;
                    $label = $this->journalLabel($journal);

                    try {
                        $outcome = $execute
                            ? $this->executeJournal($journal, $storage, isRecovery: true)
                            : $this->inspectJournal($journal, $storage);
                        $this->recordOutcome($label, $outcome);
                    } catch (Throwable $exception) {
                        $this->markJournalError($journal, $exception->getMessage());
                        $this->recordOutcome($label, 'failed', $exception->getMessage());
                    }
                }

                return ! $this->limitReached($limit);
            });
    }

    /**
     * @param  array{model: class-string<Model>, path: string, disk: string, hash: string, mime: string, size: string}  $configuration
     */
    private function processNewLegacyRecords(
        string $role,
        array $configuration,
        DocumentStorageService $storage,
        string $targetDisk,
        bool $execute,
        int $limit,
    ): void {
        $modelClass = $configuration['model'];

        $modelClass::query()
            ->when($modelClass === MeasurementPayment::class, fn (Builder $query): Builder => $query->whereDoesntHave('receiptEvidences'))
            ->whereNotNull($configuration['path'])
            ->where(fn (Builder $legacy): Builder => $legacy
                ->whereNull($configuration['disk'])
                ->orWhere($configuration['disk'], 'public'))
            ->whereDoesntHave('fileMigrationJournal', fn (Builder $journal): Builder => $journal->where('file_role', $role))
            ->orderBy('id')
            ->chunkById(100, function ($records) use ($role, $configuration, $storage, $targetDisk, $execute, $limit): bool {
                foreach ($records as $record) {
                    if ($this->limitReached($limit)) {
                        return false;
                    }

                    $this->processed++;
                    $label = $record::class." #{$record->getKey()}";
                    $sourcePath = (string) $record->getAttribute($configuration['path']);
                    $sourceHash = $storage->checksum($sourcePath, 'public');

                    if ($sourceHash === null) {
                        $this->recordOutcome($label, 'failed', 'origem pública ausente ou sem SHA-256 verificável');

                        continue;
                    }

                    if (! $execute) {
                        $this->recordOutcome($label, 'skipped');

                        continue;
                    }

                    try {
                        [$journal, $created] = $this->prepareJournal(
                            $record,
                            $role,
                            $configuration,
                            $sourcePath,
                            $sourceHash,
                            $targetDisk,
                        );
                        $outcome = $this->executeJournal($journal, $storage, isRecovery: ! $created);
                        $this->recordOutcome($label, $outcome);
                    } catch (Throwable $exception) {
                        if (isset($journal) && $journal instanceof MeasurementFileMigration) {
                            $this->markJournalError($journal, $exception->getMessage());
                        }

                        $this->recordOutcome($label, 'failed', $exception->getMessage());
                    }
                }

                return ! $this->limitReached($limit);
            });
    }

    /**
     * @param  array{model: class-string<Model>, path: string, disk: string, hash: string, mime: string, size: string}  $configuration
     * @return array{MeasurementFileMigration, bool}
     */
    private function prepareJournal(
        Model $record,
        string $role,
        array $configuration,
        string $sourcePath,
        string $sourceHash,
        string $targetDisk,
    ): array {
        return DB::transaction(function () use ($record, $role, $configuration, $sourcePath, $sourceHash, $targetDisk): array {
            $locked = $record::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $locked->getAttribute($configuration['path']) !== $sourcePath
                || ! in_array($locked->getAttribute($configuration['disk']), [null, 'public'], true)) {
                throw new RuntimeException('O registro foi alterado antes da preparação da migração.');
            }

            $existing = MeasurementFileMigration::query()
                ->where('migratable_type', $record::class)
                ->where('migratable_id', $record->getKey())
                ->where('file_role', $role)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof MeasurementFileMigration) {
                if ($existing->source_path !== $sourcePath || ! hash_equals($existing->source_sha256, $sourceHash)) {
                    throw new RuntimeException('O journal existente não corresponde à origem pública atual.');
                }

                return [$existing, false];
            }

            $targetPath = DocumentStorageService::PRIVATE_PREFIX
                ."/measurements/legacy/{$role}/{$record->getKey()}/".basename($sourcePath);

            $journal = MeasurementFileMigration::query()->create([
                'migratable_type' => $record::class,
                'migratable_id' => $record->getKey(),
                'file_role' => $role,
                'source_disk' => 'public',
                'source_path' => $sourcePath,
                'source_sha256' => $sourceHash,
                'destination_disk' => $targetDisk,
                'destination_path' => $targetPath,
                'recovery_path' => $targetPath.'.migration-recovery',
                'state' => MeasurementFileMigration::STATE_PREPARING,
            ]);

            return [$journal, true];
        });
    }

    private function executeJournal(
        MeasurementFileMigration $journal,
        DocumentStorageService $storage,
        bool $isRecovery,
    ): string {
        $configuration = $this->configurationForJournal($journal);
        $record = $this->recordForJournal($journal, $configuration);
        if ($record instanceof MeasurementPayment && $record->receiptEvidences()->exists()) {
            throw new RuntimeException('Comprovante materializado como evidência: migração legada bloqueada para preservar o arquivo.');
        }

        $recordPointsToSource = $this->recordPointsToSource($record, $journal, $configuration);
        $recordPointsToDestination = $this->recordPointsToDestination($record, $journal, $configuration);

        if (! $recordPointsToSource && ! $recordPointsToDestination) {
            throw new RuntimeException('O registro não aponta nem para a origem conhecida nem para o destino do journal.');
        }

        if ($journal->state === MeasurementFileMigration::STATE_COMPLETED
            && $recordPointsToDestination
            && ! $storage->exists($journal->source_path, $journal->source_disk)
            && $this->hasExpectedChecksum($journal->destination_path, $journal->destination_disk, $journal->source_sha256, $storage)) {
            $this->removeRecoveryCopy($journal, $storage);

            return 'already_secure';
        }

        $sourceIsValid = $this->hasExpectedChecksum(
            $journal->source_path,
            $journal->source_disk,
            $journal->source_sha256,
            $storage,
        );
        $recoveryIsValid = $this->hasExpectedChecksum(
            $journal->recovery_path,
            $journal->destination_disk,
            $journal->source_sha256,
            $storage,
        );
        $destinationIsValid = $this->hasExpectedChecksum(
            $journal->destination_path,
            $journal->destination_disk,
            $journal->source_sha256,
            $storage,
        );

        if ($recordPointsToSource && ! $sourceIsValid) {
            throw new RuntimeException('A origem pública conhecida está ausente ou diverge do SHA-256 registrado.');
        }

        if (! $recoveryIsValid) {
            if (! $sourceIsValid
                || ($storage->exists($journal->recovery_path, $journal->destination_disk)
                    && ! $recordPointsToDestination)) {
                throw new RuntimeException('A cópia privada de recuperação está ausente ou divergente.');
            }

            $recoveryIsValid = $this->copyAndVerify(
                $journal->source_disk,
                $journal->source_path,
                $journal->destination_disk,
                $journal->recovery_path,
                $journal->source_sha256,
                $storage,
                allowOverwrite: $recordPointsToDestination,
            );
        }

        if (! $recoveryIsValid) {
            throw new RuntimeException('A cópia privada de recuperação não pôde ser verificada.');
        }

        if (! $destinationIsValid) {
            if ($storage->exists($journal->destination_path, $journal->destination_disk)
                && ! $recordPointsToDestination) {
                throw new RuntimeException('O destino privado já existe com SHA-256 divergente.');
            }

            $destinationIsValid = $this->copyAndVerify(
                $journal->destination_disk,
                $journal->recovery_path,
                $journal->destination_disk,
                $journal->destination_path,
                $journal->source_sha256,
                $storage,
                allowOverwrite: $recordPointsToDestination,
            );
        }

        if (! $destinationIsValid) {
            throw new RuntimeException('O destino privado não pôde ser verificado.');
        }

        $prepared = $journal->forceFill([
            'state' => MeasurementFileMigration::STATE_PREPARED,
            'prepared_at' => $journal->prepared_at ?? now(),
            'last_error' => null,
        ])->saveQuietly();

        if (! $prepared) {
            throw new RuntimeException('O journal não confirmou a preparação das cópias privadas.');
        }

        $this->switchDatabaseToPrivate($journal, $configuration, $storage);
        $journal->refresh();
        $record = $this->recordForJournal($journal, $configuration);

        if (! $this->recordPointsToDestination($record, $journal, $configuration)
            || ! $this->hasExpectedChecksum($journal->destination_path, $journal->destination_disk, $journal->source_sha256, $storage)) {
            throw new RuntimeException('A verificação pós-commit do destino privado falhou; a origem pública foi preservada.');
        }

        $verified = $journal->forceFill([
            'state' => MeasurementFileMigration::STATE_VERIFIED,
            'verified_at' => now(),
            'last_error' => null,
        ])->saveQuietly();

        if (! $verified) {
            throw new RuntimeException('O journal não confirmou a verificação pós-commit.');
        }

        if ($storage->exists($journal->source_path, $journal->source_disk)) {
            if ($this->isLegacySourceStillReferenced($journal)) {
                $this->markSharedSourceRetained($journal);

                return 'shared_source_retained';
            }

            if (! $this->hasExpectedChecksum($journal->destination_path, $journal->destination_disk, $journal->source_sha256, $storage)
                || ! $this->hasExpectedChecksum($journal->recovery_path, $journal->destination_disk, $journal->source_sha256, $storage)) {
                throw new RuntimeException('O cleanup foi bloqueado porque as duas cópias privadas não estão íntegras.');
            }

            try {
                $deleted = Storage::disk($journal->source_disk)->delete($journal->source_path);
            } catch (Throwable $exception) {
                $deleted = false;

                if ($storage->exists($journal->source_path, $journal->source_disk)) {
                    $this->markPublicResidue($journal, $exception->getMessage());

                    return 'migrated_with_public_residue';
                }
            }

            if (! $deleted && $storage->exists($journal->source_path, $journal->source_disk)) {
                $this->markPublicResidue($journal, 'A origem pública exata não pôde ser removida.');

                return 'migrated_with_public_residue';
            }
        }

        if (! $this->hasExpectedChecksum($journal->destination_path, $journal->destination_disk, $journal->source_sha256, $storage)) {
            $restored = $this->copyAndVerify(
                $journal->destination_disk,
                $journal->recovery_path,
                $journal->destination_disk,
                $journal->destination_path,
                $journal->source_sha256,
                $storage,
                allowOverwrite: true,
            );

            if (! $restored) {
                throw new RuntimeException('O destino privado falhou após o cleanup; a cópia privada de recuperação foi preservada.');
            }

            $isRecovery = true;
        }

        $completed = $journal->forceFill([
            'state' => MeasurementFileMigration::STATE_COMPLETED,
            'cleaned_at' => now(),
            'last_error' => null,
        ])->saveQuietly();

        if (! $completed) {
            throw new RuntimeException('O journal não confirmou a conclusão do cleanup.');
        }

        $this->removeRecoveryCopy($journal, $storage);

        return $isRecovery ? 'recovered' : 'migrated';
    }

    /**
     * @param  array{model: class-string<Model>, path: string, disk: string, hash: string, mime: string, size: string}  $configuration
     */
    private function switchDatabaseToPrivate(
        MeasurementFileMigration $journal,
        array $configuration,
        DocumentStorageService $storage,
    ): void {
        DB::transaction(function () use ($journal, $configuration, $storage): void {
            $lockedJournal = MeasurementFileMigration::query()->whereKey($journal->getKey())->lockForUpdate()->firstOrFail();
            $modelClass = $configuration['model'];
            $locked = $modelClass::query()->whereKey($lockedJournal->migratable_id)->lockForUpdate()->firstOrFail();

            if ($locked instanceof MeasurementPayment && $locked->receiptEvidences()->exists()) {
                throw new RuntimeException('O comprovante foi materializado como evidência; o DB switch legado foi bloqueado.');
            }

            if (! $this->hasExpectedChecksum(
                $lockedJournal->destination_path,
                $lockedJournal->destination_disk,
                $lockedJournal->source_sha256,
                $storage,
            )) {
                throw new RuntimeException('O destino privado perdeu integridade antes do DB switch.');
            }

            if ($this->recordPointsToSource($locked, $lockedJournal, $configuration)) {
                $metadata = $storage->metadata($lockedJournal->destination_path, $lockedJournal->destination_disk);
                $saved = $locked->forceFill([
                    $configuration['path'] => $lockedJournal->destination_path,
                    $configuration['disk'] => $lockedJournal->destination_disk,
                    $configuration['hash'] => $lockedJournal->source_sha256,
                    $configuration['mime'] => is_string($metadata['mime_type']) ? $metadata['mime_type'] : 'application/octet-stream',
                    $configuration['size'] => is_int($metadata['size_bytes']) ? $metadata['size_bytes'] : 0,
                ])->saveQuietly();

                if (! $saved) {
                    throw new RuntimeException('O banco recusou a persistência do destino privado.');
                }
            } elseif (! $this->recordPointsToDestination($locked, $lockedJournal, $configuration)) {
                throw new RuntimeException('O registro foi alterado durante o DB switch.');
            }

            $confirmed = $locked->fresh();

            if (! $confirmed instanceof Model
                || ! $this->recordPointsToDestination($confirmed, $lockedJournal, $configuration)) {
                throw new RuntimeException('O banco não confirmou o destino privado.');
            }

            $savedJournal = $lockedJournal->forceFill([
                'state' => MeasurementFileMigration::STATE_SWITCHED,
                'switched_at' => $lockedJournal->switched_at ?? now(),
                'last_error' => null,
            ])->saveQuietly();

            if (! $savedJournal) {
                throw new RuntimeException('O journal não confirmou o DB switch.');
            }
        });
    }

    private function inspectJournal(MeasurementFileMigration $journal, DocumentStorageService $storage): string
    {
        $configuration = $this->configurationForJournal($journal);
        $record = $this->recordForJournal($journal, $configuration);
        $destinationIsValid = $this->hasExpectedChecksum(
            $journal->destination_path,
            $journal->destination_disk,
            $journal->source_sha256,
            $storage,
        );
        $publicExists = $storage->exists($journal->source_path, $journal->source_disk);

        if ($journal->state === MeasurementFileMigration::STATE_COMPLETED
            && ! $publicExists
            && $destinationIsValid
            && $this->recordPointsToDestination($record, $journal, $configuration)) {
            return 'already_secure';
        }

        if ($publicExists && $this->recordPointsToDestination($record, $journal, $configuration) && $destinationIsValid) {
            if ($this->isLegacySourceStillReferenced($journal)) {
                return 'shared_source_retained';
            }

            return 'migrated_with_public_residue';
        }

        return 'failed';
    }

    private function reconcileRetainedSharedSources(DocumentStorageService $storage, bool $execute): void
    {
        MeasurementFileMigration::query()
            ->where('state', MeasurementFileMigration::STATE_SHARED_SOURCE_RETAINED)
            ->orderBy('id')
            ->each(function (MeasurementFileMigration $journal) use ($storage, $execute): void {
                if ($this->isLegacySourceStillReferenced($journal)) {
                    if (! $execute) {
                        $this->recordOutcome($this->journalLabel($journal), 'shared_source_retained');
                    }

                    return;
                }

                try {
                    $outcome = $execute
                        ? $this->executeJournal($journal, $storage, isRecovery: true)
                        : $this->inspectJournal($journal, $storage);

                    if (in_array($outcome, ['migrated_with_public_residue', 'failed', 'shared_source_retained'], true)) {
                        $this->recordOutcome($this->journalLabel($journal), $outcome);

                        return;
                    }

                    $this->line($this->journalLabel($journal).': cleanup compartilhado reconciliado.');
                } catch (Throwable $exception) {
                    $this->markJournalError($journal, $exception->getMessage());
                    $this->recordOutcome($this->journalLabel($journal), 'failed', $exception->getMessage());
                }
            });
    }

    private function isLegacySourceStillReferenced(MeasurementFileMigration $journal): bool
    {
        if ($this->isEvidenceFile($journal->source_disk, $journal->source_path)) {
            return true;
        }

        foreach (self::FILE_CONFIGURATIONS as $role => $configuration) {
            $modelClass = $configuration['model'];
            $query = $modelClass::query()
                ->where($configuration['path'], $journal->source_path)
                ->where(function (Builder $disk) use ($configuration, $journal): void {
                    if ($journal->source_disk === 'public') {
                        $disk->whereNull($configuration['disk'])
                            ->orWhere($configuration['disk'], 'public');

                        return;
                    }

                    $disk->where($configuration['disk'], $journal->source_disk);
                });

            if ($configuration['model'] === $journal->migratable_type && $role === $journal->file_role) {
                $query->whereKeyNot($journal->migratable_id);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    private function detectUnownedLegacyResidues(DocumentStorageService $storage, string $targetDisk, int $limit): void
    {
        try {
            $publicFiles = Storage::disk('public')->allFiles();
        } catch (Throwable $exception) {
            $this->failed++;
            $this->components->warn("Não foi possível inspecionar resíduos públicos sem journal: {$exception->getMessage()}");

            return;
        }

        if ($publicFiles === []) {
            return;
        }

        $filesByBasename = collect($publicFiles)->groupBy(fn (string $path): string => basename($path));

        foreach (self::FILE_CONFIGURATIONS as $role => $configuration) {
            $modelClass = $configuration['model'];
            $legacyPrefix = DocumentStorageService::PRIVATE_PREFIX."/measurements/legacy/{$role}/";

            $modelClass::query()
                ->where($configuration['disk'], $targetDisk)
                ->where($configuration['path'], 'like', $legacyPrefix.'%')
                ->whereNotNull($configuration['hash'])
                ->whereDoesntHave('fileMigrationJournal', fn (Builder $journal): Builder => $journal->where('file_role', $role))
                ->orderBy('id')
                ->chunkById(100, function ($records) use ($configuration, $filesByBasename, $storage, $limit): bool {
                    foreach ($records as $record) {
                        if ($this->limitReached($limit)) {
                            return false;
                        }

                        $privatePath = (string) $record->getAttribute($configuration['path']);
                        $expectedHash = (string) $record->getAttribute($configuration['hash']);
                        $candidates = $filesByBasename
                            ->get(basename($privatePath), collect())
                            ->filter(fn (string $publicPath): bool => hash_equals(
                                $expectedHash,
                                (string) $storage->checksum($publicPath, 'public'),
                            ));

                        if ($candidates->isEmpty()) {
                            continue;
                        }

                        $this->processed++;
                        $this->failed++;
                        $this->components->warn(
                            $record::class." #{$record->getKey()}: candidato público sem ownership registrado; nenhuma exclusão foi realizada.",
                        );
                    }

                    return ! $this->limitReached($limit);
                });
        }
    }

    /**
     * @return array{model: class-string<Model>, path: string, disk: string, hash: string, mime: string, size: string}
     */
    private function configurationForJournal(MeasurementFileMigration $journal): array
    {
        $configuration = self::FILE_CONFIGURATIONS[$journal->file_role] ?? null;

        if (! is_array($configuration) || $configuration['model'] !== $journal->migratable_type) {
            throw new RuntimeException('O journal possui tipo de arquivo não suportado.');
        }

        return $configuration;
    }

    /**
     * @param  array{model: class-string<Model>, path: string, disk: string, hash: string, mime: string, size: string}  $configuration
     */
    private function recordForJournal(MeasurementFileMigration $journal, array $configuration): Model
    {
        $modelClass = $configuration['model'];
        $record = $modelClass::query()->find($journal->migratable_id);

        if (! $record instanceof Model) {
            throw new RuntimeException('O registro associado ao journal não existe mais.');
        }

        return $record;
    }

    /**
     * @param  array{model: class-string<Model>, path: string, disk: string, hash: string, mime: string, size: string}  $configuration
     */
    private function recordPointsToSource(Model $record, MeasurementFileMigration $journal, array $configuration): bool
    {
        return (string) $record->getAttribute($configuration['path']) === $journal->source_path
            && in_array($record->getAttribute($configuration['disk']), [null, $journal->source_disk], true);
    }

    /**
     * @param  array{model: class-string<Model>, path: string, disk: string, hash: string, mime: string, size: string}  $configuration
     */
    private function recordPointsToDestination(Model $record, MeasurementFileMigration $journal, array $configuration): bool
    {
        return (string) $record->getAttribute($configuration['path']) === $journal->destination_path
            && $record->getAttribute($configuration['disk']) === $journal->destination_disk
            && is_string($record->getAttribute($configuration['hash']))
            && hash_equals($journal->source_sha256, $record->getAttribute($configuration['hash']));
    }

    private function isEvidenceFile(string $disk, string $path): bool
    {
        return MeasurementPaymentReceiptEvidence::query()->where('storage_path', $path)
            ->where(fn (Builder $query): Builder => $disk === 'public'
                ? $query->whereNull('storage_disk')->orWhereIn('storage_disk', ['', 'public'])
                : $query->where('storage_disk', $disk))->exists();
    }

    private function copyAndVerify(
        string $sourceDisk,
        string $sourcePath,
        string $destinationDisk,
        string $destinationPath,
        string $expectedHash,
        DocumentStorageService $storage,
        bool $allowOverwrite,
    ): bool {
        if ($this->isEvidenceFile($destinationDisk, $destinationPath)) {
            throw new RuntimeException('O destino pertence a uma evidência imutável e não pode ser sobrescrito.');
        }

        if ($storage->exists($destinationPath, $destinationDisk)) {
            if ($this->hasExpectedChecksum($destinationPath, $destinationDisk, $expectedHash, $storage)) {
                return true;
            }

            if (! $allowOverwrite) {
                return false;
            }
        }

        $stream = Storage::disk($sourceDisk)->readStream($sourcePath);

        if (! is_resource($stream)) {
            return false;
        }

        try {
            if (! Storage::disk($destinationDisk)->writeStream($destinationPath, $stream)) {
                return false;
            }
        } finally {
            fclose($stream);
        }

        return $this->hasExpectedChecksum($destinationPath, $destinationDisk, $expectedHash, $storage);
    }

    private function hasExpectedChecksum(
        string $path,
        string $disk,
        string $expectedHash,
        DocumentStorageService $storage,
    ): bool {
        $actualHash = $storage->checksum($path, $disk);

        return is_string($actualHash) && hash_equals($expectedHash, $actualHash);
    }

    private function markPublicResidue(MeasurementFileMigration $journal, string $message): void
    {
        $saved = $journal->forceFill([
            'state' => MeasurementFileMigration::STATE_PUBLIC_RESIDUE,
            'last_error' => $message,
        ])->saveQuietly();

        if (! $saved) {
            throw new RuntimeException('O journal não confirmou o resíduo público pendente.');
        }
    }

    private function markSharedSourceRetained(MeasurementFileMigration $journal): void
    {
        $saved = $journal->forceFill([
            'state' => MeasurementFileMigration::STATE_SHARED_SOURCE_RETAINED,
            'last_error' => null,
        ])->saveQuietly();

        if (! $saved) {
            throw new RuntimeException('O journal não confirmou a retenção legítima da origem compartilhada.');
        }
    }

    private function markJournalError(MeasurementFileMigration $journal, string $message): void
    {
        rescue(
            fn (): bool => $journal->fresh()?->forceFill(['last_error' => $message])->saveQuietly() ?? false,
            report: true,
        );
    }

    private function removeRecoveryCopy(MeasurementFileMigration $journal, DocumentStorageService $storage): void
    {
        if ($this->isEvidenceFile($journal->destination_disk, $journal->recovery_path)) {
            return;
        }

        if (! $storage->exists($journal->recovery_path, $journal->destination_disk)) {
            return;
        }

        rescue(
            fn (): bool => Storage::disk($journal->destination_disk)->delete($journal->recovery_path),
            report: true,
        );
    }

    private function recordOutcome(string $label, string $outcome, ?string $detail = null): void
    {
        $message = $detail === null ? "{$label}: {$outcome}." : "{$label}: {$outcome} — {$detail}.";

        match ($outcome) {
            'migrated' => $this->migrated++,
            'shared_source_retained' => $this->sharedRetained++,
            'migrated_with_public_residue' => $this->residues++,
            'recovered' => $this->recovered++,
            'already_secure' => $this->alreadySecure++,
            'skipped' => $this->skipped++,
            default => $this->failed++,
        };

        if (in_array($outcome, ['failed', 'migrated_with_public_residue'], true)) {
            $this->components->warn($message);

            return;
        }

        $this->line($message);
    }

    private function journalLabel(MeasurementFileMigration $journal): string
    {
        return "{$journal->migratable_type} #{$journal->migratable_id}";
    }

    private function limitReached(int $limit): bool
    {
        return $limit > 0 && $this->processed >= $limit;
    }

    private function resetCounters(): void
    {
        $this->processed = 0;
        $this->migrated = 0;
        $this->sharedRetained = 0;
        $this->residues = 0;
        $this->failed = 0;
        $this->skipped = 0;
        $this->alreadySecure = 0;
        $this->recovered = 0;
    }
}
