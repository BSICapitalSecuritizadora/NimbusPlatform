<?php

namespace App\Filament\Resources\Nimbus\Announcements\Pages;

use App\Filament\Resources\Nimbus\Announcements\AnnouncementResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected static ?string $title = 'Editar Aviso Geral';

    protected static ?string $breadcrumb = 'Editar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-announcement-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Atualize o conteúdo, nível de alerta ou período de exibição do aviso.';
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Salvar alterações')
            ->icon('heroicon-m-check')
            ->color('primary');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir')
                ->color('danger'),
        ];
    }
}
