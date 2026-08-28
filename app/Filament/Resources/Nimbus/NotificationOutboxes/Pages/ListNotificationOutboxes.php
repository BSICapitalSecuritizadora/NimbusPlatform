<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes\Pages;

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use App\Models\Nimbus\NotificationOutbox;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListNotificationOutboxes extends ListRecords
{
    protected static string $resource = NotificationOutboxResource::class;

    protected static ?string $title = 'Auditoria de Envios';

    protected static ?string $breadcrumb = 'Listar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-notification-outboxes-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhe o processamento, as tentativas e o resultado dos envios realizados pela plataforma.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $totalCount = NotificationOutbox::query()->count();
        $sentCount = NotificationOutbox::query()->where('status', 'SENT')->count();
        $pendingCount = NotificationOutbox::query()->whereIn('status', ['PENDING', 'SENDING'])->count();
        $failedCount = NotificationOutbox::query()->where('status', 'FAILED')->count();
        $cancelledCount = NotificationOutbox::query()->where('status', 'CANCELLED')->count();

        return [
            'todos' => Tab::make('Todos')
                ->badge($totalCount)
                ->badgeColor('gray'),
            'concluidos' => Tab::make('Concluídos')
                ->badge($sentCount)
                ->badgeColor($sentCount > 0 ? 'success' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'SENT')),
            'aguardando' => Tab::make('Aguardando')
                ->badge($pendingCount)
                ->badgeColor($pendingCount > 0 ? 'warning' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['PENDING', 'SENDING'])),
            'falhas' => Tab::make('Falhas')
                ->badge($failedCount)
                ->badgeColor($failedCount > 0 ? 'danger' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'FAILED')),
            'cancelados' => Tab::make('Cancelados')
                ->badge($cancelledCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'CANCELLED')),
        ];
    }
}
