<?php

namespace App\Filament\Resources\Banks\Pages;

use App\Filament\Resources\Banks\BankResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditBank extends EditRecord
{
    protected static string $resource = BankResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Editar banco';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize os dados e o logotipo da instituição bancária.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-simple-form-page bsi-bank-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir banco')
                ->visible(fn (): bool => BankResource::canDelete($this->getRecord())),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Banco atualizado com sucesso.';
    }
}
