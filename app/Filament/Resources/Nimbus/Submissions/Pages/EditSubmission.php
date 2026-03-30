<?php

namespace App\Filament\Resources\Nimbus\Submissions\Pages;

use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubmission extends EditRecord
{
    protected static string $resource = SubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
