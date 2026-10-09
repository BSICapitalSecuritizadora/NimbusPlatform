<?php

namespace App\Services;

use App\Concerns\ScansUploadedFile;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Services\Security\ClamAvFileScanner;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Gravação, validação e compensação dos arquivos da Medição.
 *
 * A instância vive a requisição inteira -- `#[Scoped]`: o worker da fila a
 * descarta entre um job e outro, e nenhuma requisição herda a anterior --
 * porque guarda os caminhos que a própria requisição gravou
 * ({@see self::wasStoredDuringThisRequest()}). Uma flag estática vazaria entre
 * jobs do mesmo worker. Pelo mesmo motivo o antivírus não fica preso ao
 * construtor: é resolvido a cada varredura, e a primeira resolução do serviço
 * não decide sozinha qual scanner vale para o resto da requisição.
 */
#[Scoped]
class MeasurementFileValidationService
{
    /**
     * Caminhos gravados por {@see self::storeAsset()} nesta requisição.
     *
     * @var array<string, true>
     */
    private array $storedAssetPaths = [];

    public function __construct(
        private DocumentStorageService $storage,
    ) {}

    public function storeAsset(UploadedFile $file): string
    {
        Validator::make(['asset' => $file], [
            'asset' => ['required', 'file', 'extensions:'.implode(',', config('uploads.measurement.allowed_extensions', [])), 'max:'.config('uploads.measurement.max_kb', 51200)],
        ])->validate();

        $directory = 'measurements/assets/'.Str::uuid();
        $disk = DocumentStorageService::privateDisk();
        $path = $this->storage->privateDirectoryPath($directory).'/'.$file->hashName();
        $this->compensateAssetOnRollback($path, $disk);

        try {
            $this->storage->storePrivateFile($file, $directory);
        } catch (Throwable $exception) {
            $this->discardUnreferencedAsset($path, $disk);

            throw $exception;
        }

        $this->storedAssetPaths[$path] = true;

        return $path;
    }

    /**
     * O caminho saiu de {@see self::storeAsset()} nesta mesma requisição?
     *
     * É o único caminho novo que o formulário de Enviar/Editar aceita além do
     * que já está gravado no arquivo. O Repeater com relationship valida cada
     * item de novo depois do upload, quando o estado já traz o caminho recém-
     * gravado -- que ainda não é o original do registro. Um caminho digitado no
     * payload nunca passa por aqui: é isso que impede vincular à medição o
     * arquivo de outra operação ou de outro módulo do mesmo disco.
     */
    public function wasStoredDuringThisRequest(string $path): bool
    {
        return isset($this->storedAssetPaths[$path]);
    }

    public function compensateAssetOnRollback(string $path, string $disk): void
    {
        foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback(fn () => $this->discardUnreferencedAsset($path, $disk));
            }
        }
    }

    public function discardUnreferencedAsset(string $path, string $disk): void
    {
        rescue(function () use ($path, $disk): void {
            if (! $this->storage->isAllowedMeasurementWriteDisk($disk)
                || ! $this->storage->isSafeStoredPath($path)
                || ! str_starts_with($path, DocumentStorageService::PRIVATE_PREFIX.'/measurements/assets/')) {
                return;
            }

            foreach ([MeasurementAsset::class, Measurement::class, MeasurementPaymentReceiptEvidence::class] as $model) {
                if ($model::query()->where('storage_disk', $disk)->where('storage_path', $path)->exists()) {
                    return;
                }
            }

            if (MeasurementPayment::query()->where('receipt_disk', $disk)->where('receipt_path', $path)->exists()) {
                return;
            }

            if (! Storage::disk($disk)->delete($path)) {
                throw new \RuntimeException('Não foi possível compensar o arquivo de Engenharia rejeitado.');
            }
        }, report: true);
    }

    public function validateAsset(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement', 'asset', allowLegacyPublic: false);
        $this->scanStoredFile($path, $disk, 'asset');
    }

    public function validateStoredAsset(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement', 'asset', allowLegacyPublic: true);
    }

    /**
     * O arquivo que a revisão herda da revisão anterior: o mesmo arquivo
     * gravado, referenciado de novo por um arquivo da revisão. Tem de estar
     * num armazenamento de escrita -- nunca no legado público --, passar pelo
     * tipo real, extensão e tamanho, e o conteúdo lido agora (`$actualSha256`,
     * calculado do disco na gravação) tem de ser o que a Engenharia aprovou na
     * revisão anterior. A varredura antivírus roda antes, fora da transação e
     * dos locks ({@see self::scanInheritedAsset()}); o SHA-256 conferido aqui,
     * sob o lock, prova que o conteúdo varrido é o que a revisão herdou.
     */
    public function validateInheritedAsset(string $path, string $disk, ?string $actualSha256, ?string $expectedSha256): void
    {
        $this->validate($path, $disk, 'measurement', 'asset', allowLegacyPublic: false);

        if (! is_string($actualSha256)
            || ! is_string($expectedSha256)
            || mb_strlen($expectedSha256) !== 64
            || ! hash_equals($expectedSha256, $actualSha256)) {
            throw ValidationException::withMessages([
                'asset' => 'O arquivo herdado da revisão anterior não corresponde mais ao conteúdo aprovado pela Engenharia (SHA-256 divergente).',
            ]);
        }
    }

    /**
     * Varredura antivírus do arquivo que uma revisão vai herdar. O arquivo pode
     * ter chegado antes do antivírus (legado protegido depois pelo comando de
     * migração, que não varre), então a herança não presume a varredura antiga.
     */
    public function scanInheritedAsset(string $path, string $disk): void
    {
        $this->scanStoredFile($path, $disk, 'asset');
    }

    public function validateReceipt(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement_receipt', 'receipt', allowLegacyPublic: false);
        $this->scanStoredFile($path, $disk, 'receipt');
    }

    public function validateStoredReceipt(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement_receipt', 'receipt', allowLegacyPublic: true);
    }

    /**
     * Varre o arquivo gravado e recusa o que não sair limpo.
     *
     * Toda recusa vai para o log como crítica, como em
     * {@see ScansUploadedFile::rejectUploadedFile()}: com o antivírus fora do
     * ar, todo envio da Medição falha, e sem o registro a operação só
     * descobriria pela reclamação de quem não consegue enviar.
     */
    private function scanStoredFile(string $path, string $disk, string $errorKey): void
    {
        $scanner = app(ClamAvFileScanner::class);

        if (! $scanner->isEnabled()) {
            return;
        }

        $stream = rescue(fn () => Storage::disk($disk)->readStream($path), null, report: false);
        $readable = is_resource($stream);

        try {
            $result = $readable
                ? rescue(fn (): string => $scanner->scanStream($stream), ClamAvFileScanner::RESULT_UNAVAILABLE)
                : ClamAvFileScanner::RESULT_UNAVAILABLE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($result !== ClamAvFileScanner::RESULT_CLEAN) {
            Log::critical('Upload bloqueado pela varredura antivírus.', [
                'reason' => match (true) {
                    $result === ClamAvFileScanner::RESULT_INFECTED => 'malware_detectado',
                    ! $readable => 'arquivo_ilegivel_para_varredura',
                    default => 'antivirus_indisponivel',
                },
                'field' => $errorKey,
                'disk' => $disk,
                'relative_path' => $path,
            ]);

            throw ValidationException::withMessages([
                $errorKey => $result === ClamAvFileScanner::RESULT_INFECTED
                    ? 'O arquivo foi bloqueado pelo antivírus. Envie um arquivo seguro.'
                    : 'Não foi possível verificar a segurança do arquivo. Tente novamente quando o antivírus estiver disponível.',
            ]);
        }
    }

    private function validate(
        string $path,
        string $disk,
        string $configuration,
        string $errorKey,
        bool $allowLegacyPublic,
    ): void {
        $allowedDisk = $allowLegacyPublic
            ? $this->storage->isAllowedMeasurementReadDisk($disk)
            : $this->storage->isAllowedMeasurementWriteDisk($disk);

        if (! $allowedDisk
            || ! $this->storage->isSafeStoredPath($path)
            || ! $this->storage->exists($path, $disk)) {
            throw ValidationException::withMessages([
                $errorKey => 'O arquivo enviado não foi encontrado em um armazenamento permitido.',
            ]);
        }

        $metadata = $this->storage->metadata($path, $disk);
        $mimeType = $metadata['mime_type'];
        $size = $metadata['size_bytes'];
        $allowedMimes = (array) config("uploads.{$configuration}.allowed_mimes", []);
        $allowedExtensions = array_map(
            static fn (mixed $extension): string => Str::lower((string) $extension),
            (array) config("uploads.{$configuration}.allowed_extensions", []),
        );
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));
        $maximumBytes = (int) config("uploads.{$configuration}.max_bytes", 0);

        if (! is_int($size) || $size < 1 || ($maximumBytes > 0 && $size > $maximumBytes)) {
            throw ValidationException::withMessages([
                $errorKey => 'O arquivo enviado está vazio ou excede o limite permitido.',
            ]);
        }

        if (! is_string($mimeType)
            || ! in_array($mimeType, $allowedMimes, true)
            || ! in_array($extension, $allowedExtensions, true)
            || ! $this->extensionMatchesMime($extension, $mimeType)
            || ! $this->contentMatchesMime($path, $disk, $mimeType)) {
            throw ValidationException::withMessages([
                $errorKey => 'O tipo real ou a extensão do arquivo enviado não é permitido.',
            ]);
        }
    }

    private function extensionMatchesMime(string $extension, string $mimeType): bool
    {
        return match ($mimeType) {
            'application/pdf' => $extension === 'pdf',
            'image/jpeg' => in_array($extension, ['jpg', 'jpeg'], true),
            'image/png' => $extension === 'png',
            default => false,
        };
    }

    private function contentMatchesMime(string $path, string $disk, string $mimeType): bool
    {
        $prefix = $this->storage->readPrefix($path, $disk, 8);

        if (! is_string($prefix)) {
            return false;
        }

        return match ($mimeType) {
            'application/pdf' => str_starts_with($prefix, '%PDF-'),
            'image/jpeg' => str_starts_with($prefix, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($prefix, "\x89PNG\r\n\x1A\n"),
            default => false,
        };
    }
}
