<?php

namespace App\Filament\Resources\Proposals\Tables;

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Proposal;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProposalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('distribution_sequence')
                    ->label('# Fila')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.cnpj')
                    ->label('CNPJ')
                    ->searchable(),
                TextColumn::make('contact.name')
                    ->label('Contato')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('representative.name')
                    ->label('Representante')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Não atribuído'),
                TextColumn::make('latestContinuationAccess.status_label')
                    ->label('Link')
                    ->state(fn (Proposal $record): string => $record->latestContinuationAccess?->status_label ?? 'Sem envio')
                    ->badge()
                    ->color(fn (Proposal $record): string => $record->latestContinuationAccess?->status_color ?? 'gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, Proposal $record): string => $record->status_label)
                    ->color(fn (?string $state): string => ProposalStatus::colorFor($state)),
                TextColumn::make('distributed_at')
                    ->label('Distribuída')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('latestStatusHistory.changed_at')
                    ->label('Última movimentação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('latestContinuationAccess.last_accessed_at')
                    ->label('Último acesso link')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label('Complementada')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Entrada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                ProposalResource::getChangeStatusAction(),
                Action::make('resend_access')
                    ->label('Reenviar acesso')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->visible(fn (Proposal $record): bool => filled($record->contact?->email))
                    ->action(function (Proposal $record): void {
                        app(SendProposalContinuationLink::class)->handle(
                            $record->loadMissing(['company', 'contact']),
                        );

                        Notification::make()
                            ->title('Novo magic link enviado ao cliente.')
                            ->success()
                            ->send();
                    }),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('distribution_sequence', 'desc');
    }
}
