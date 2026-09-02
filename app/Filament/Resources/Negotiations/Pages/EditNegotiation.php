<?php

namespace App\Filament\Resources\Negotiations\Pages;

use App\Filament\Resources\Negotiations\NegotiationResource;
use App\Models\Negotiation;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditNegotiation extends EditRecord
{
    protected static string $resource = NegotiationResource::class;

    protected static ?string $title = 'Editar Negociação';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Atualize os dados e as movimentações comerciais desta competência.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Visualizar')
                ->icon('heroicon-m-eye')
                ->outlined()
                ->color('gray')
                ->visible(fn (Negotiation $record): bool => NegotiationResource::canView($record)),
            DeleteAction::make()
                ->label('Excluir')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->visible(fn (Negotiation $record): bool => NegotiationResource::canDelete($record)),
        ];
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

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Negociação atualizada com sucesso.';
    }
}
