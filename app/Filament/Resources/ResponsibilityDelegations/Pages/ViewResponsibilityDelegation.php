<?php

namespace App\Filament\Resources\ResponsibilityDelegations\Pages;

use App\Filament\Resources\ResponsibilityDelegations\ResponsibilityDelegationResource;
use App\Services\ResponsibilityDelegationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewResponsibilityDelegation extends ViewRecord
{
    protected static string $resource = ResponsibilityDelegationResource::class;

    protected static ?string $title = 'Delegação';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revoke')
                ->label('Revogar')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('revocation_reason')
                        ->label('Motivo da revogação')
                        ->required()
                        ->maxLength(1000),
                ])
                ->modalHeading('Revogar delegação')
                ->visible(fn (): bool => in_array($this->record->status, ['active', 'scheduled'], true))
                ->authorize('revoke', $this->record)
                ->action(function (array $data): void {
                    app(ResponsibilityDelegationService::class)->revokeDelegation(
                        $this->record,
                        auth()->user(),
                        $data['revocation_reason'],
                    );
                    $this->redirect(ResponsibilityDelegationResource::getUrl('index'));
                }),
        ];
    }
}
