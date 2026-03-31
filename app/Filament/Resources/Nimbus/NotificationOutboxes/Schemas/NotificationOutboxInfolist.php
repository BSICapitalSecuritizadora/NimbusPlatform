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
                Section::make('Informações da mensagem')
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
                            ->label('Nome do destinatário')
                            ->placeholder('—'),
                        TextEntry::make('subject')
                            ->label('Assunto'),
                        TextEntry::make('template')
                            ->label('Template'),
                        TextEntry::make('correlation_id')
                            ->label('Correlação')
                            ->placeholder('—'),
                        TextEntry::make('attempts')
                            ->label('Tentativas')
                            ->state(fn (NotificationOutbox $record): string => "{$record->attempts}/{$record->max_attempts}"),
                        TextEntry::make('next_attempt_at')
                            ->label('Próxima tentativa')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('sent_at')
                            ->label('Enviado em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('last_error')
                            ->label('Último erro')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Payload')
                    ->schema([
                        KeyValueEntry::make('payload_json')
                            ->label('Dados enviados')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
