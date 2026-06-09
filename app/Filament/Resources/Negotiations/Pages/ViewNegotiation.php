<?php

namespace App\Filament\Resources\Negotiations\Pages;

use App\Filament\Resources\Negotiations\NegotiationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNegotiation extends ViewRecord
{
    protected static string $resource = NegotiationResource::class;

    protected static ?string $title = 'Visualizar Negociação';

    protected static ?string $breadcrumb = 'Visualizar';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
