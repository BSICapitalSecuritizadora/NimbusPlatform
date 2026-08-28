<?php

namespace App\Filament\Resources\Nimbus\Announcements\Pages;

use App\Filament\Resources\Nimbus\Announcements\AnnouncementResource;
use App\Models\Nimbus\Announcement;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected static ?string $title = 'Avisos Gerais';

    protected static ?string $breadcrumb = 'Listar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-announcements-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie comunicados e avisos exibidos aos usuários da plataforma.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo aviso')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }

    public function getTabs(): array
    {
        $now = now();

        $totalCount = Announcement::query()->count();
        $activeCount = Announcement::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->count();

        $scheduledCount = Announcement::query()
            ->where('is_active', true)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->count();

        $endedCount = Announcement::query()
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->count();

        $inactiveCount = Announcement::query()
            ->where('is_active', false)
            ->count();

        return [
            'todos' => Tab::make('Todos')
                ->badge($totalCount)
                ->badgeColor('gray'),
            'ativos' => Tab::make('Ativos')
                ->badge($activeCount)
                ->badgeColor($activeCount > 0 ? 'success' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                    ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))),
            'agendados' => Tab::make('Agendados')
                ->badge($scheduledCount)
                ->badgeColor($scheduledCount > 0 ? 'info' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereNotNull('starts_at')
                    ->where('starts_at', '>', $now)),
            'encerrados' => Tab::make('Encerrados')
                ->badge($endedCount)
                ->badgeColor($endedCount > 0 ? 'gray' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<', $now)),
            'inativos' => Tab::make('Inativos')
                ->badge($inactiveCount)
                ->badgeColor($inactiveCount > 0 ? 'danger' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
        ];
    }
}
