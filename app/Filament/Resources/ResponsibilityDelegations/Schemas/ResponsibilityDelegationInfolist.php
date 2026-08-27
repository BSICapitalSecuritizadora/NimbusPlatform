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
                    ->state(fn (ResponsibilityDelegation $record): string => app(ResponsibilityDelegationService::class)->effectiveStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'scheduled' => 'info',
                        'expired' => 'warning',
                        'revoked' => 'danger',
                        'ineffective' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Ativa',
                        'scheduled' => 'Agendada',
                        'expired' => 'Expirada',
                        'revoked' => 'Revogada',
                        'ineffective' => 'Ineficaz',
                        default => $state,
                    }),

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
