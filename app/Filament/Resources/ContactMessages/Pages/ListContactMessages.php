<?php

namespace App\Filament\Resources\ContactMessages\Pages;

use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Models\ContactMessage;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListContactMessages extends ListRecords
{
    protected static string $resource = ContactMessageResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-contact-messages-list-page',
    ];

    public function getTitle(): string
    {
        return 'Mensagens de contato';
    }

    public function getSubheading(): ?string
    {
        return 'Acompanhe as mensagens recebidas pelos canais de contato do site.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $counts = ContactMessage::query()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as new_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as in_progress_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as done_count
            ', [ContactMessage::STATUS_NEW, ContactMessage::STATUS_IN_PROGRESS, ContactMessage::STATUS_DONE])
            ->first();

        $totalCount = (int) ($counts?->total ?? 0);
        $newCount = (int) ($counts?->new_count ?? 0);
        $inProgressCount = (int) ($counts?->in_progress_count ?? 0);
        $doneCount = (int) ($counts?->done_count ?? 0);

        return [
            'todas' => Tab::make('Todas')
                ->badge($totalCount)
                ->badgeColor('gray'),
            'novas' => Tab::make('Novas')
                ->badge($newCount)
                ->badgeColor($newCount > 0 ? 'warning' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ContactMessage::STATUS_NEW)),
            'em_atendimento' => Tab::make('Em atendimento')
                ->badge($inProgressCount)
                ->badgeColor($inProgressCount > 0 ? 'info' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ContactMessage::STATUS_IN_PROGRESS)),
            'respondidas' => Tab::make('Respondidas')
                ->badge($doneCount)
                ->badgeColor($doneCount > 0 ? 'success' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ContactMessage::STATUS_DONE)),
        ];
    }
}
