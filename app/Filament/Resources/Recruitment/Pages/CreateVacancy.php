<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Enums\VacancyStatus;
use App\Filament\Resources\Recruitment\VacancyResource;
use App\Services\Recruitment\VacancySlugService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateVacancy extends CreateRecord
{
    protected static string $resource = VacancyResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Cadastrar vaga';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-vacancy-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre os dados da oportunidade, publicação, remuneração e conteúdo exibido aos candidatos.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar vaga')
            ->icon('heroicon-m-plus')
            ->color('primary');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outra')
            ->color('gray');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

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
