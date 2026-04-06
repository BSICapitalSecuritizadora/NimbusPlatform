<?php

namespace App\Actions\Nimbus;

use App\Models\Nimbus\SubmissionFile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PreviewAdminSubmissionFile
{
    public function handle(?User $user, SubmissionFile $file): BinaryFileResponse
    {
        $this->assertAdminPanelAccess($user);

        if (! Storage::disk('local')->exists($file->storage_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->file(
            Storage::disk('local')->path($file->storage_path),
            [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$file->original_name.'"',
            ],
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
