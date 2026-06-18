<?php

namespace App\Services\Obligations;

use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Services\DocumentStorageService;
use App\Services\Security\ClamAvFileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ObligationEvidenceService
{
    public const STORAGE_DIRECTORY = 'obligation-evidences';

    public function __construct(
        protected DocumentStorageService $documentStorageService,
        protected ObligationHistoryRecorder $historyRecorder,
        protected ClamAvFileScanner $fileScanner,
    ) {}

    /**
     * Validate and persist an evidence file for an obligation, storing it on the
     * private disk and recording the upload in the obligation history.
     *
     * @throws ValidationException
     */
    public function store(
        Obligation $obligation,
        UploadedFile $file,
        ?string $description = null,
        ?int $userId = null,
    ): ObligationEvidence {
        $this->validate($file);
        $this->scanForMalware($file);

        $stored = $this->documentStorageService->storePrivateFile($file, self::STORAGE_DIRECTORY);

        $evidence = ObligationEvidence::create([
            'obligation_id' => $obligation->id,
            'emission_id' => $obligation->emission_id,
            'uploaded_by' => $userId,
            'original_name' => $stored['original_name'],
            'path' => $stored['path'],
            'disk' => $stored['disk'],
            'mime_type' => $stored['mime_type'],
            'size' => $stored['size_bytes'],
            'description' => filled($description) ? Str::limit($description, 1000, '') : null,
            'uploaded_at' => now(),
        ]);

        $this->historyRecorder->recordEvidenceUploaded($obligation, $evidence);

        return $evidence;
    }

    /**
     * Soft-delete an evidence, keeping the physical file for auditability, and
     * record the removal in the obligation history.
     */
    public function delete(ObligationEvidence $evidence): void
    {
        $obligation = $evidence->obligation;

        $evidence->delete();

        if ($obligation !== null) {
            $this->historyRecorder->recordEvidenceRemoved($obligation, $evidence);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function validate(UploadedFile $file): void
    {
        $allowedExtensions = (array) config('uploads.obligation_evidence.allowed_extensions', []);
        $maxKb = (int) config('uploads.obligation_evidence.max_kb', 20480);

        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => 'Tipo de arquivo não permitido. Envie PDF, DOC, DOCX, XLS, XLSX, CSV, PNG ou JPG.',
            ]);
        }

        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'file' => 'O arquivo enviado excede o tamanho máximo permitido de '.(int) ceil($maxKb / 1024).' MB.',
            ]);
        }
    }

    /**
     * Run an optional, synchronous antivirus check before persisting the file.
     * When ClamAV is disabled (default) this is a no-op and never blocks the
     * upload. When enabled, an infected or unverifiable file is rejected with a
     * friendly message while technical details stay in the log.
     *
     * @throws ValidationException
     */
    protected function scanForMalware(UploadedFile $file): void
    {
        if (! $this->fileScanner->isEnabled()) {
            return;
        }

        $result = $this->fileScanner->scan($file->getRealPath() ?: null);

        if ($result === ClamAvFileScanner::RESULT_INFECTED) {
            Log::critical('ObligationEvidence: arquivo bloqueado pelo antivírus.', [
                'original_name' => $file->getClientOriginalName(),
            ]);

            throw ValidationException::withMessages([
                'file' => 'O arquivo enviado não passou na verificação de segurança e foi bloqueado.',
            ]);
        }

        if ($result === ClamAvFileScanner::RESULT_UNAVAILABLE) {
            Log::error('ObligationEvidence: antivírus indisponível — upload bloqueado por segurança.', [
                'original_name' => $file->getClientOriginalName(),
            ]);

            throw ValidationException::withMessages([
                'file' => 'Não foi possível validar a segurança do arquivo no momento. Tente novamente mais tarde.',
            ]);
        }
    }

    /**
     * Whether the underlying physical file still exists on its disk.
     */
    public function fileExists(ObligationEvidence $evidence): bool
    {
        return Storage::disk($evidence->disk)->exists($evidence->path);
    }
}
