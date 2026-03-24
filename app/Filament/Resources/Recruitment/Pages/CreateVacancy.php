<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Filament\Resources\Recruitment\VacancyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVacancy extends CreateRecord
{
    protected static string $resource = VacancyResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
