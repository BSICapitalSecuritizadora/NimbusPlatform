<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes\Schemas;

use App\Models\Nimbus\NotificationOutbox;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NotificationOutboxInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações da Mensagem')
                    ->schema([
                        TextEntry::make('status_label')
                            ->label('Status')
                            ->badge()
                            ->color(fn (NotificationOutbox $record): string => $record->status_color),
                        TextEntry::make('type_label')
                            ->label('Classificação'),
                        TextEntry::make('recipient_email')
                            ->label('Destinatário'),
                        TextEntry::make('recipient_name')
                            ->label('Nome do Destinatário')
                            ->placeholder('—'),
                        TextEntry::make('subject')
                            ->label('Assunto'),
                        TextEntry::make('template')
                            ->label('Template'),
                        TextEntry::make('correlation_id')
                            ->label('ID de Correlação')
                            ->placeholder('—'),
                        TextEntry::make('attempts')
                            ->label('Tentativas')
                            ->state(fn (NotificationOutbox $record): string => "{$record->attempts} de {$record->max_attempts}"),
                        TextEntry::make('next_attempt_at')
                            ->label('Próxima Tentativa')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('sent_at')
                            ->label('Data de Envio')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('last_error')
                            ->label('Último Erro')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Dados Técnicos (Payload)')
                    ->schema([
                        KeyValueEntry::make('payload_json')
                            ->label('Parâmetros Enviados')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
