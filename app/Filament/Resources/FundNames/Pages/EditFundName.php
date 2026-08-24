<?php

namespace App\Filament\Resources\FundNames\Pages;

use App\Filament\Resources\FundNames\FundNameResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFundName extends EditRecord
{
    protected static string $resource = FundNameResource::class;

    protected static ?string $title = 'Editar nome de fundo';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize a denominação ou o tipo de fundo vinculado.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-fund-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir nome de fundo'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Nome de fundo atualizado com sucesso.';
    }
}
