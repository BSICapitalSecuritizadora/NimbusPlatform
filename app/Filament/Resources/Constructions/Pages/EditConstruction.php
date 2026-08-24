<?php

namespace App\Filament\Resources\Constructions\Pages;

use App\Filament\Resources\Constructions\ConstructionResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditConstruction extends EditRecord
{
    protected static string $resource = ConstructionResource::class;

    protected static ?string $title = 'Editar obra';

    protected ?string $subheading = 'Atualize os dados cadastrais, o cronograma e as informações financeiras da obra.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DeleteAction::make()
                    ->label('Excluir obra'),
            ])
                ->label('Mais ações')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Obra atualizada com sucesso.';
    }
}
