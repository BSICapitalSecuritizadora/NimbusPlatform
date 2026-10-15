<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Models\BankAccount;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBankAccount extends ViewRecord
{
    protected static string $resource = BankAccountResource::class;

    protected static ?string $title = 'Visualizar conta bancária';

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-bank-account-view-page',
    ];

    public function getSubheading(): ?string
    {
        /** @var BankAccount $account */
        $account = $this->getRecord();

        return $account->optionLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar'),
            DeleteAction::make()
                ->label('Excluir conta')
                ->visible(fn (BankAccount $record): bool => BankAccountResource::canDelete($record)),
        ];
    }
}
