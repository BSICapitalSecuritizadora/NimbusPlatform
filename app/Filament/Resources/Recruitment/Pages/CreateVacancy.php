<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Enums\VacancyStatus;
use App\Filament\Resources\Recruitment\VacancyResource;
use App\Services\Recruitment\VacancySlugService;
use Filament\Resources\Pages\CreateRecord;

class CreateVacancy extends CreateRecord
{
    protected static string $resource = VacancyResource::class;

    protected static ?string $title = 'Cadastrar Vaga';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Vaga cadastrada com sucesso.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['slug'] ?? null) && filled($data['title'] ?? null)) {
            $data['slug'] = VacancySlugService::generate($data['title']);
        }

        if (($data['status'] ?? null) === VacancyStatus::Published->value && blank($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
