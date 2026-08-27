<?php

namespace App\Filament\Resources\ResponsibilityDelegations\Pages;

use App\Filament\Resources\ResponsibilityDelegations\ResponsibilityDelegationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListResponsibilityDelegations extends ListRecords
{
    protected static string $resource = ResponsibilityDelegationResource::class;

    protected static ?string $title = 'Delegações de Responsabilidade';

    public function getSubheading(): ?string
    {
        return 'Gestão de delegações temporárias — quem delegou, para quem, escopo e vigência.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Delegação')
                ->icon('heroicon-m-plus'),
        ];
    }
}
