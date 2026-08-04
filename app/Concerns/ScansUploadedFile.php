<?php

namespace App\Concerns;

use App\Enums\MalwareScanStatus;
use App\Services\Security\ClamAvFileScanner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Varre o arquivo recém-gravado e registra o veredito em `scan_status`.
 *
 * Estes documentos chegam pelo painel, onde o arquivo já foi gravado no disco
 * pelo `FileUpload` antes do model ser salvo. A varredura acontece de forma
 * síncrona, no `saving`, para que um arquivo reprovado impeça a criação do
 * registro em vez de virar um download disponível até a fila alcançá-lo — é o
 * mesmo desenho já usado nas evidências de obrigação.
 *
 * Com o ClamAV desabilitado o veredito é `clean`, seguindo o contrato do
 * {@see ClamAvFileScanner}: ambientes sem clamd não ficam bloqueados.
 *
 * @property MalwareScanStatus $scan_status
 */
trait ScansUploadedFile
{
    public static function bootScansUploadedFile(): void
    {
        static::saving(function (self $model): void {
            $model->scanUploadedFile();
        });
    }

    /**
     * Disco em que o arquivo referenciado pelo model está gravado.
     */
    abstract public function uploadedFileDisk(): string;

    /**
     * Coluna que guarda o caminho do arquivo no disco.
     */
    public function uploadedFilePathColumn(): string
    {
        return 'file_path';
    }

    /**
     * @throws ValidationException
     */
    protected function scanUploadedFile(): void
    {
        $pathColumn = $this->uploadedFilePathColumn();

        if (! $this->isDirty($pathColumn)) {
            return;
        }

        $path = (string) $this->getAttribute($pathColumn);

        if ($path === '') {
            return;
        }

        $this->setAttribute('scan_status', $this->resolveUploadedFileScanStatus($path));
    }

    /**
     * @throws ValidationException
     */
    protected function resolveUploadedFileScanStatus(string $path): MalwareScanStatus
    {
        $fileScanner = app(ClamAvFileScanner::class);

        if (! $fileScanner->isEnabled()) {
            return MalwareScanStatus::Clean;
        }

        $fileStream = rescue(
            fn () => Storage::disk($this->uploadedFileDisk())->readStream($path),
            null,
            report: false,
        );

        if (! is_resource($fileStream)) {
            $this->rejectUploadedFile('arquivo_ilegivel_para_varredura', $path);
        }

        try {
            $result = $fileScanner->scanStream($fileStream);
        } finally {
            fclose($fileStream);
        }

        if ($result === ClamAvFileScanner::RESULT_INFECTED) {
            $this->rejectUploadedFile('malware_detectado', $path);
        }

        if ($result === ClamAvFileScanner::RESULT_UNAVAILABLE) {
            $this->rejectUploadedFile('antivirus_indisponivel', $path);
        }

        return MalwareScanStatus::Clean;
    }

    /**
     * @throws ValidationException
     */
    protected function rejectUploadedFile(string $reason, string $path): never
    {
        Log::critical('Upload bloqueado pela varredura antivírus.', [
            'reason' => $reason,
            'model' => static::class,
            'disk' => $this->uploadedFileDisk(),
            'relative_path' => $path,
        ]);

        throw ValidationException::withMessages([
            $this->uploadedFilePathColumn() => $reason === 'malware_detectado'
                ? 'O arquivo enviado não passou na verificação de segurança e foi bloqueado.'
                : 'Não foi possível validar a segurança do arquivo no momento. Tente novamente mais tarde.',
        ]);
    }
}
