<?php

namespace App\Filament\Resources\ResponsibilityDelegations\Schemas;

use App\Models\ResponsibilityDelegation;
use App\Services\ResponsibilityDelegationService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ResponsibilityDelegationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $delegations = app(ResponsibilityDelegationService::class);

        return $schema
            ->components([
                TextEntry::make('delegator.name')
                    ->label('Delegante'),

                TextEntry::make('delegate.name')
                    ->label('Delegado'),

                TextEntry::make('scope_type')
                    ->label('Escopo')
                    ->formatStateUsing(fn (string $state): string => ResponsibilityDelegation::SCOPE_OPTIONS[$state] ?? $state)
                    ->badge(),

                TextEntry::make('scopeOperation.code')
                    ->label('Operação')
                    ->placeholder('—'),

                TextEntry::make('scope_stage')
                    ->label('Etapa')
                    ->formatStateUsing(fn (?int $state): string => $state ? (ResponsibilityDelegation::STAGE_OPTIONS[$state] ?? (string) $state) : '—')
                    ->placeholder('—'),

                TextEntry::make('scope_responsibility_label')
                    ->label('Responsabilidade')
                    ->placeholder('—'),

                TextEntry::make('starts_at')
                    ->label('Início')
                    ->dateTime('d/m/Y H:i'),

                TextEntry::make('ends_at')
                    ->label('Término')
                    ->dateTime('d/m/Y H:i'),

                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ResponsibilityDelegation $record): string => $delegations->effectiveness($record)->status)
                    ->color(fn (ResponsibilityDelegation $record): string => $delegations->effectiveness($record)->statusColor())
                    ->formatStateUsing(fn (ResponsibilityDelegation $record): string => $delegations->effectiveness($record)->statusLabel()),

                // Só aparece quando há o que explicar: uma delegação ativa não
                // ganha uma linha vazia por causa disto.
                TextEntry::make('ineffectiveness_reason')
                    ->label('Por que está ineficaz')
                    ->state(fn (ResponsibilityDelegation $record): ?string => $delegations->effectiveness($record)->reasonLabel())
                    ->visible(fn (ResponsibilityDelegation $record): bool => $delegations->effectiveness($record)->reason !== null)
                    ->icon('heroicon-m-exclamation-triangle')
                    ->color('danger')
                    ->columnSpanFull(),

                TextEntry::make('reason')
                    ->label('Motivo')
                    ->columnSpanFull(),

                TextEntry::make('revoked_at')
                    ->label('Revogada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),

                TextEntry::make('revokedByUser.name')
                    ->label('Revogada por')
                    ->placeholder('—'),

                TextEntry::make('revocation_reason')
                    ->label('Motivo da revogação')
                    ->placeholder('—')
                    ->columnSpanFull(),

                TextEntry::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i'),
            ]);
    }
}
