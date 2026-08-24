<?php

namespace App\Filament\Resources\FundTypes\Pages;

use App\Filament\Resources\FundTypes\FundTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditFundType extends EditRecord
{
    protected static string $resource = FundTypeResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Editar tipo de fundo';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize a denominação do tipo de fundo cadastrado.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-simple-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir tipo de fundo'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Tipo de fundo atualizado com sucesso.';
    }
}
