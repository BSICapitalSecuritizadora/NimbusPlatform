<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Pages;

use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use App\Models\Nimbus\PortalUser;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListPortalUsers extends ListRecords
{
    protected static string $resource = PortalUserResource::class;

    protected static ?string $title = 'Usuários do Portal';

    protected static ?string $breadcrumb = 'Listar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-portal-users-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gerencie os usuários externos, seus dados de acesso e métodos de autenticação.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo usuário')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary'),
        ];
    }

    public function getTabs(): array
    {
        $totalCount = PortalUser::query()->count();
        $activeCount = PortalUser::query()->where('status', 'ACTIVE')->count();
        $invitedCount = PortalUser::query()->where('status', 'INVITED')->count();
        $blockedCount = PortalUser::query()->where('status', 'BLOCKED')->count();
        $inactiveCount = PortalUser::query()->where('status', 'INACTIVE')->count();

        return [
            'todos' => Tab::make('Todos')
                ->badge($totalCount)
                ->badgeColor('gray'),
            'ativos' => Tab::make('Ativos')
                ->badge($activeCount)
                ->badgeColor($activeCount > 0 ? 'success' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'ACTIVE')),
            'aguardando' => Tab::make('Aguardando Cadastro')
                ->badge($invitedCount)
                ->badgeColor($invitedCount > 0 ? 'warning' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'INVITED')),
            'suspensos' => Tab::make('Suspensos')
                ->badge($blockedCount)
                ->badgeColor($blockedCount > 0 ? 'danger' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'BLOCKED')),
            'inativos' => Tab::make('Inativos')
                ->badge($inactiveCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'INACTIVE')),
        ];
    }
}
