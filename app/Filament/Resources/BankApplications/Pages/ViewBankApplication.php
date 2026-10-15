<?php

namespace App\Filament\Resources\BankApplications\Pages;

use App\Filament\Resources\BankApplications\BankApplicationResource;
use App\Models\BankApplication;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBankApplication extends ViewRecord
{
    protected static string $resource = BankApplicationResource::class;

    protected static ?string $title = 'Visualizar aplicação bancária';

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-bank-application-view-page',
    ];

    public function getSubheading(): ?string
    {
        /** @var BankApplication $application */
        $application = $this->getRecord();

        return $application->optionLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar'),
            DeleteAction::make()
                ->label('Excluir aplicação')
                ->visible(fn (BankApplication $record): bool => BankApplicationResource::canDelete($record)),
        ];
    }
}
