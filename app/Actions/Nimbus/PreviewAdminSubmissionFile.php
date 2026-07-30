<?php

namespace App\Actions\Nimbus;

use App\Enums\MalwareScanStatus;
use App\Models\Nimbus\SubmissionFile;
use App\Models\User;
use App\Services\DocumentStorageService;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreviewAdminSubmissionFile
{
    public function __construct(
        protected DocumentStorageService $documentStorageService,
    ) {}

    public function handle(?User $user, SubmissionFile $file): BinaryFileResponse|StreamedResponse
    {
        $this->assertAdminPanelAccess($user);

        abort_unless($file->scan_status === MalwareScanStatus::Clean, Response::HTTP_NOT_FOUND);

        if (! $this->documentStorageService->privateExists($file->storage_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $this->documentStorageService->previewPrivate(
            $file->storage_path,
            $file->mime_type,
            $file->original_name,
        );
    }

    protected function assertAdminPanelAccess(?User $user): void
    {
        $adminPanel = Filament::getPanel('admin');

        abort_unless(
            $user
                && $user->canAccessPanel($adminPanel)
                && ($user->hasAnyRole(['super-admin', 'admin']) || $user->can('nimbus.submissions.view')),
            Response::HTTP_FORBIDDEN,
        );
    }
}
