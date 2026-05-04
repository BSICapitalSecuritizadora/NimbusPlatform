<?php

namespace App\Actions\Nimbus;

use App\Models\Nimbus\SubmissionFile;
use App\Models\User;
use App\Services\DocumentStorageService;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadAdminSubmissionFile
{
    public function __construct(
        protected DocumentStorageService $documentStorageService,
    ) {}

    public function handle(?User $user, SubmissionFile $file): StreamedResponse
    {
        $this->assertAdminPanelAccess($user);

        if (! $this->documentStorageService->privateExists($file->storage_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $this->documentStorageService->downloadPrivate($file->storage_path, $file->original_name);
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
