<?php

namespace App\Filament\Resources\BankApplications\Pages;

use App\Filament\Resources\BankApplications\BankApplicationResource;
use App\Models\BankApplication;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankApplication extends EditRecord
{
    protected static string $resource = BankApplicationResource::class;

    protected static ?string $title = 'Editar aplicação bancária';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize o produto bancário e seus vínculos com os fundos.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-construction-form-page bsi-bank-application-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir aplicação')
                ->visible(fn (BankApplication $record): bool => BankApplicationResource::canDelete($record)),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Aplicação bancária atualizada com sucesso.';
    }
}
