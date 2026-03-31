<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes\Tables;

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use App\Models\Nimbus\NotificationOutbox;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NotificationOutboxesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (NotificationOutbox $record): string => $record->status_color)
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('status', $direction)),
                TextColumn::make('type_label')
                    ->label('Classificação')
                    ->searchable(['type'])
                    ->badge(),
                TextColumn::make('recipient_email')
                    ->label('Destinatário')
                    ->searchable(),
                TextColumn::make('subject')
                    ->label('Assunto')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('attempts')
                    ->label('Tentativas')
                    ->state(fn (NotificationOutbox $record): string => "{$record->attempts}/{$record->max_attempts}"),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'PENDING' => 'Aguardando',
                        'SENDING' => 'Enviando',
                        'SENT' => 'Concluído',
                        'FAILED' => 'Falhou',
                        'CANCELLED' => 'Cancelado',
                    ]),
                SelectFilter::make('type')
                    ->label('Classificação')
                    ->options([
                        'token_created' => 'Criação de Token',
                        'password_reset' => 'Redefinição de Senha',
                        'welcome_email' => 'Boas-vindas',
                        'submission_received' => 'Protocolo Recebido',
                        'user_precreated' => 'Pré-cadastro de Usuário',
                        'new_announcement' => 'Novo Comunicado',
                        'new_general_document' => 'Documento Publicado',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                NotificationOutboxResource::getCancelAction(),
                NotificationOutboxResource::getReprocessAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
