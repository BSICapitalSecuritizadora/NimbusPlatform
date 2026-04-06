<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminSubmissionResponseFilesRequest;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminSubmissionFileController extends Controller
{
    private const STORAGE_DISK = 'local';

    public function preview(Request $request, SubmissionFile $file): BinaryFileResponse
    {
        $this->authorizeAdminPanelAccess($request);

        if (! Storage::disk(self::STORAGE_DISK)->exists($file->storage_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->file(
            Storage::disk(self::STORAGE_DISK)->path($file->storage_path),
            [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$file->original_name.'"',
            ],
        );
    }

    public function download(Request $request, SubmissionFile $file): StreamedResponse
    {
        $this->authorizeAdminPanelAccess($request);

        if (! Storage::disk(self::STORAGE_DISK)->exists($file->storage_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return Storage::disk(self::STORAGE_DISK)->download($file->storage_path, $file->original_name);
    }

    public function storeResponseFiles(StoreAdminSubmissionResponseFilesRequest $request, Submission $submission): RedirectResponse
    {
        $this->authorizeAdminPanelAccess($request);

        $uploaded = 0;
        $errors = [];
        $isVisibleToUser = $request->boolean('visible_to_user', true);

        foreach ($request->file('response_files', []) as $uploadedFile) {
            try {
                $path = $uploadedFile->store("nimbus/submissions/{$submission->id}/responses", self::STORAGE_DISK);
                $storedName = basename($path);
                $checksum = hash_file('sha256', $uploadedFile->getRealPath()) ?: null;

                $submissionFile = $submission->files()->create([
                    'document_type' => 'OTHER',
                    'origin' => 'ADMIN',
                    'visible_to_user' => $isVisibleToUser,
                    'original_name' => $uploadedFile->getClientOriginalName(),
                    'stored_name' => $storedName,
                    'mime_type' => $uploadedFile->getMimeType(),
                    'size_bytes' => $uploadedFile->getSize(),
                    'storage_path' => $path,
                    'checksum' => $checksum,
                    'uploaded_at' => now(),
                ]);

                $submissionFile->versions()->create([
                    'version' => 1,
                    'original_name' => $uploadedFile->getClientOriginalName(),
                    'stored_name' => $storedName,
                    'storage_path' => $path,
                    'size_bytes' => $uploadedFile->getSize(),
                    'mime_type' => $uploadedFile->getMimeType(),
                    'checksum' => $checksum,
                    'uploaded_by_type' => 'ADMIN',
                    'uploaded_by_id' => $request->user()?->id,
                    'notes' => null,
                ]);

                $uploaded++;
            } catch (Throwable $exception) {
                $errors[] = $uploadedFile->getClientOriginalName().': '.$exception->getMessage();
            }
        }

        if ($uploaded > 0) {
            Notification::make()
                ->success()
                ->title('Documentos de retorno enviados com sucesso.')
                ->body("{$uploaded} arquivo(s) foram anexados a este envio.")
                ->send();
        }

        if ($errors !== []) {
            Notification::make()
                ->warning()
                ->title('Alguns arquivos não puderam ser enviados.')
                ->body(implode("\n", $errors))
                ->send();
        }

        return back();
    }

    private function authorizeAdminPanelAccess(Request $request): void
    {
        $user = $request->user();
        $adminPanel = Filament::getPanel('admin');

        abort_unless(
            $user && $user->canAccessPanel($adminPanel),
            Response::HTTP_FORBIDDEN,
        );
    }
}
