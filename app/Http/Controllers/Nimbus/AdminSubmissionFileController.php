<?php

namespace App\Http\Controllers\Nimbus;

use App\Actions\Nimbus\DownloadAdminSubmissionFile;
use App\Actions\Nimbus\PreviewAdminSubmissionFile;
use App\Actions\Nimbus\StoreAdminSubmissionResponseFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminSubmissionResponseFilesRequest;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSubmissionFileController extends Controller
{
    public function preview(
        Request $request,
        SubmissionFile $file,
        PreviewAdminSubmissionFile $previewAdminSubmissionFile,
    ): BinaryFileResponse {
        return $previewAdminSubmissionFile->handle($request->user(), $file);
    }

    public function download(
        Request $request,
        SubmissionFile $file,
        DownloadAdminSubmissionFile $downloadAdminSubmissionFile,
    ): StreamedResponse {
        return $downloadAdminSubmissionFile->handle($request->user(), $file);
    }

    public function storeResponseFiles(
        StoreAdminSubmissionResponseFilesRequest $request,
        Submission $submission,
        StoreAdminSubmissionResponseFiles $storeAdminSubmissionResponseFiles,
    ): RedirectResponse {
        $result = $storeAdminSubmissionResponseFiles->handle(
            $request->toDTO(),
            $submission,
            $request->user(),
        );

        if ($result['uploaded'] > 0) {
            Notification::make()
                ->success()
                ->title('Documentos de retorno enviados com sucesso.')
                ->body("{$result['uploaded']} arquivo(s) foram anexados a este envio.")
                ->send();
        }

        if ($result['errors'] !== []) {
            Notification::make()
                ->warning()
                ->title('Alguns arquivos não puderam ser enviados.')
                ->body(implode("\n", $result['errors']))
                ->send();
        }

        return back();
    }
}
