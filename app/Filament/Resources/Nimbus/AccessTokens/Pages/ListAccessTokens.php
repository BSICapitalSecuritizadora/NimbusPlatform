<?php

namespace App\Filament\Resources\Nimbus\AccessTokens\Pages;

use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use App\Models\Nimbus\AccessToken;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListAccessTokens extends ListRecords
{
    protected static string $resource = AccessTokenResource::class;

    protected static ?string $title = 'Chaves de Acesso';

    protected static ?string $breadcrumb = 'Listar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-access-tokens-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhe as chaves de acesso geradas para os usuários do portal e seus períodos de validade.';
    }

    public function getTabs(): array
    {
        return [
            'todos' => Tab::make('Todos')
                ->badge(AccessToken::query()->count()),

            'validas' => Tab::make('Válidas')
                ->badge(AccessToken::query()->whereNull('used_at')->whereNotIn('status', ['REVOKED', 'USED'])->where('expires_at', '>=', now())->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNull('used_at')
                    ->whereNotIn('status', ['REVOKED', 'USED'])
                    ->where('expires_at', '>=', now())),

            'utilizadas' => Tab::make('Utilizadas')
                ->badge(AccessToken::query()->where(fn (Builder $query) => $query->whereNotNull('used_at')->orWhere('status', 'USED'))->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where(fn (Builder $sq) => $sq->whereNotNull('used_at')->orWhere('status', 'USED'))),

            'expiradas' => Tab::make('Expiradas')
                ->badge(AccessToken::query()->whereNull('used_at')->where('status', '!=', 'REVOKED')->where('expires_at', '<', now())->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNull('used_at')
                    ->where('status', '!=', 'REVOKED')
                    ->where('expires_at', '<', now())),

            'revogadas' => Tab::make('Revogadas')
                ->badge(AccessToken::query()->where('status', 'REVOKED')->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', 'REVOKED')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
