<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-documents-list-page',
    ];

    public function getTitle(): string
    {
        return 'Documentos';
    }

    public function getSubheading(): ?string
    {
        return 'Gestão institucional e governança de documentos, controle de versões, publicação por série e distribuição pública.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('batch_create')
                ->label('Cadastrar documentos em lote')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color('gray')
                ->url(fn (): string => DocumentResource::getUrl('batch'))
                ->visible(fn (): bool => DocumentResource::canCreate()),

            CreateAction::make()
                ->label('Criar Documento')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }

    public function getTabs(): array
    {
        $counts = Document::query()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_published = 0 AND is_public = 0 THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN is_published = 1 AND is_public = 0 THEN 1 ELSE 0 END) as published_count,
                SUM(CASE WHEN is_public = 1 THEN 1 ELSE 0 END) as public_count,
                SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END) as unpublished_count
            ')
            ->first();

        $totalCount = (int) ($counts?->total ?? 0);
        $draftCount = (int) ($counts?->draft_count ?? 0);
        $publishedCount = (int) ($counts?->published_count ?? 0);
        $publicCount = (int) ($counts?->public_count ?? 0);
        $unpublishedCount = (int) ($counts?->unpublished_count ?? 0);

        return [
            'todos' => Tab::make('Todos')
                ->badge($totalCount)
                ->badgeColor('gray'),
            'rascunho' => Tab::make('Rascunho')
                ->badge($draftCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', false)->where('is_public', false)),
            'publicado' => Tab::make('Publicado')
                ->badge($publishedCount)
                ->badgeColor($publishedCount > 0 ? 'info' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', true)->where('is_public', false)),
            'publico' => Tab::make('Público')
                ->badge($publicCount)
                ->badgeColor($publicCount > 0 ? 'success' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_public', true)),
            'nao_publicado' => Tab::make('Não Publicado')
                ->badge($unpublishedCount)
                ->badgeColor($unpublishedCount > 0 ? 'warning' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', false)),
        ];
    }
}
