<?php

namespace App\Filament\Resources\Invitations\Pages;

use App\Filament\Resources\Invitations\InvitationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvitations extends ListRecords
{
    protected static string $resource = InvitationResource::class;

    protected static ?string $title = 'Convites de acesso';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-invitations-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie convites enviados para acesso à plataforma e acompanhe sua utilização.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar convite')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
