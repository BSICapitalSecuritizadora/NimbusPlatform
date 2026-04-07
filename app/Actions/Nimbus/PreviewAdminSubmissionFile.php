<?php

namespace App\Actions\Nimbus;

use App\Models\Nimbus\SubmissionFile;
use App\Models\User;
use App\Services\DocumentStorageService;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PreviewAdminSubmissionFile
{
    public function __construct(
        protected DocumentStorageService $documentStorageService,
    ) {}

    public function handle(?User $user, SubmissionFile $file): BinaryFileResponse
    {
        $this->assertAdminPanelAccess($user);

        if (! $this->documentStorageService->exists($file->storage_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $this->documentStorageService->preview(
            $file->storage_path,
            $file->mime_type,
            $file->original_name,
        );
    }

    protected function assertAdminPanelAccess(?User $user): void
    {
        $adminPanel = Filament::getPanel('admin');

        abort_unless(
            $user && $user->canAccessPanel($adminPanel),
            Response::HTTP_FORBIDDEN,
        );
    }
}
