<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Models\BankAccount;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankAccount extends EditRecord
{
    protected static string $resource = BankAccountResource::class;

    protected static ?string $title = 'Editar conta bancária';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize a identificação canônica, o contexto e a configuração de integração da conta.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-construction-form-page bsi-bank-account-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir conta')
                ->visible(fn (BankAccount $record): bool => BankAccountResource::canDelete($record)),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Conta bancária atualizada com sucesso.';
    }
}
