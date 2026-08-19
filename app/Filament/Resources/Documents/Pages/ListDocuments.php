<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('batch_create')
                ->label('Cadastrar documentos em lote')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color('gray')
                ->url(fn (): string => DocumentResource::getUrl('batch'))
                ->visible(fn (): bool => DocumentResource::canCreate()),

            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'todos' => Tab::make('Todos'),
            'rascunho' => Tab::make('Rascunho')
                ->modifyQueryUsing(fn ($query) => $query->where('is_published', false)->where('is_public', false)),
            'publicado' => Tab::make('Publicado')
                ->modifyQueryUsing(fn ($query) => $query->where('is_published', true)->where('is_public', false)),
            'publico' => Tab::make('Público')
                ->modifyQueryUsing(fn ($query) => $query->where('is_public', true)),
            'nao_publicado' => Tab::make('Não Publicado')
                ->modifyQueryUsing(fn ($query) => $query->where('is_published', false)),
        ];
    }
}
