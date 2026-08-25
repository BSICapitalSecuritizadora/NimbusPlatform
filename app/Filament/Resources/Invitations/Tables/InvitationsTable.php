<?php

namespace App\Filament\Resources\Invitations\Tables;

use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Resources\Invitations\Pages\ListInvitations;
use App\Models\Invitation;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('E-mail')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('invitedBy.name')
                    ->label('Convidado por')
                    ->description(fn (Invitation $record): ?string => $record->invitedBy?->email)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expira em')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Invitation $record): string => $record->expires_at->diffForHumans())
                    ->sortable(),
                TextColumn::make('used_at')
                    ->label('Utilizado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Invitation $record): string => match (true) {
                        $record->used_at !== null => 'Utilizado',
                        $record->expires_at->isPast() => 'Expirado',
                        default => 'Pendente',
                    })
                    ->color(fn (Invitation $record): string => match (true) {
                        $record->used_at !== null => 'success',
                        $record->expires_at->isPast() => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->searchPlaceholder('Buscar por e-mail...')
            ->stackedOnMobile()
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedEnvelope)
            ->emptyStateHeading(fn (ListInvitations $livewire): string => self::hasActiveSearch($livewire)
                ? 'Nenhum convite encontrado'
                : 'Nenhum convite de acesso')
            ->emptyStateDescription(fn (ListInvitations $livewire): ?string => self::hasActiveSearch($livewire)
                ? null
                : 'Crie um convite para conceder acesso a um novo usuário da plataforma.')
            ->emptyStateActions([
                Action::make('createInvitation')
                    ->label('Criar primeiro convite')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->url(fn (): string => InvitationResource::getUrl('create'))
                    ->visible(fn (ListInvitations $livewire): bool => InvitationResource::canCreate() && (! self::hasActiveSearch($livewire))),
                Action::make('clearTableSearch')
                    ->label('Limpar busca')
                    ->icon('heroicon-m-x-mark')
                    ->color('gray')
                    ->action(fn (ListInvitations $livewire) => $livewire->resetTableSearch())
                    ->visible(fn (ListInvitations $livewire): bool => self::hasActiveSearch($livewire)),
            ])
            ->defaultSort('created_at', 'desc');
    }

    protected static function hasActiveSearch(ListInvitations $livewire): bool
    {
        return filled($livewire->tableSearch);
    }
}
