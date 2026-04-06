<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\SubmissionFile;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
