<?php

namespace App\Actions\Nimbus;

use App\DTOs\Nimbus\StoreSubmissionFileDTO;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use App\Services\DocumentManager;

class StoreSubmissionFile
{
    public function __construct(private readonly DocumentManager $documentManager) {}

    public function handle(Submission $submission, StoreSubmissionFileDTO $dto): SubmissionFile
    {
        return $this->documentManager->storeSubmissionFile($submission, $dto);
    }
}
