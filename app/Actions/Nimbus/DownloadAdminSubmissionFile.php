<?php

namespace App\Actions\Nimbus;

use App\Models\Nimbus\SubmissionFile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadAdminSubmissionFile
{
    public function handle(?User $user, SubmissionFile $file): StreamedResponse
    {
        $this->assertAdminPanelAccess($user);

        if (! Storage::disk('local')->exists($file->storage_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return Storage::disk('local')->download($file->storage_path, $file->original_name);
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
