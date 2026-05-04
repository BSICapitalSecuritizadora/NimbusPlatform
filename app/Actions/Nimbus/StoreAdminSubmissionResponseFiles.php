<?php

namespace App\Actions\Nimbus;

use App\DTOs\Nimbus\StoreAdminSubmissionResponseFilesDTO;
use App\DTOs\Nimbus\StoreSubmissionFileDTO;
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
        StoreAdminSubmissionResponseFilesDTO $dto,
        Submission $submission,
        ?User $user,
    ): array {
        $user = $this->authorizedUser($user);
        $uploaded = 0;
        $errors = [];
        $isVisibleToUser = $dto->visibleToUser;

        foreach ($dto->responseFiles as $uploadedFile) {
            try {
                $this->storeSubmissionFile->handle($submission, new StoreSubmissionFileDTO(
                    file: $uploadedFile,
                    documentType: 'OTHER',
                    origin: 'ADMIN',
                    visibleToUser: $isVisibleToUser,
                    uploadedByType: 'ADMIN',
                    uploadedById: $user->id,
                ));

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
            $user
                && $user->canAccessPanel($adminPanel)
                && ($user->hasAnyRole(['super-admin', 'admin']) || $user->can('nimbus.submissions.update')),
            Response::HTTP_FORBIDDEN,
        );

        return $user;
    }
}
