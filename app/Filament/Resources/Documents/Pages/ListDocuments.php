<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'todos' => \Filament\Schemas\Components\Tabs\Tab::make('Todos'),
            'rascunho' => \Filament\Schemas\Components\Tabs\Tab::make('Rascunho')
                ->modifyQueryUsing(fn ($query) => $query->where('is_published', false)->where('is_public', false)),
            'publicado' => \Filament\Schemas\Components\Tabs\Tab::make('Publicado')
                ->modifyQueryUsing(fn ($query) => $query->where('is_published', true)->where('is_public', false)),
            'publico' => \Filament\Schemas\Components\Tabs\Tab::make('Público')
                ->modifyQueryUsing(fn ($query) => $query->where('is_public', true)),
            'nao_publicado' => \Filament\Schemas\Components\Tabs\Tab::make('Não Publicado')
                ->modifyQueryUsing(fn ($query) => $query->where('is_published', false)),
        ];
    }
}
