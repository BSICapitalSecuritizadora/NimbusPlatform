<?php

namespace App\Actions\Nimbus;

use App\Http\Requests\StoreAdminSubmissionResponseFilesRequest;
use App\Models\Nimbus\Submission;
use App\Models\User;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StoreAdminSubmissionResponseFiles
{
    public function __construct(
        protected StoreSubmissionFile $storeSubmissionFile,
    ) {}

    /**
     * @return array{uploaded:int,errors:array<int,string>}
     */
    public function handle(
        StoreAdminSubmissionResponseFilesRequest $request,
        Submission $submission,
    ): array {
        $user = $this->authorizedUser($request->user());
        $uploaded = 0;
        $errors = [];
        $isVisibleToUser = $request->boolean('visible_to_user', true);

        foreach ($request->file('response_files', []) as $uploadedFile) {
            try {
                $this->storeSubmissionFile->handle(
                    submission: $submission,
                    file: $uploadedFile,
                    documentType: 'OTHER',
                    origin: 'ADMIN',
                    visibleToUser: $isVisibleToUser,
                    uploadedByType: 'ADMIN',
                    uploadedById: $user->id,
                );

                $uploaded++;
            } catch (Throwable $exception) {
                $errors[] = $uploadedFile->getClientOriginalName().': '.$exception->getMessage();
            }
        }

        return [
            'uploaded' => $uploaded,
            'errors' => $errors,
        ];
    }

    protected function authorizedUser(?User $user): User
    {
        $adminPanel = Filament::getPanel('admin');

        abort_unless(
            $user && $user->canAccessPanel($adminPanel),
            Response::HTTP_FORBIDDEN,
        );

        return $user;
    }
}
