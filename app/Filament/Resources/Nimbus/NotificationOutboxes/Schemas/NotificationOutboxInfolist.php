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
                    ->icon('heroicon-o-envelope')
                    ->schema([
                        TextEntry::make('status_label')
                            ->label('Status')
                            ->badge()
                            ->color(fn (NotificationOutbox $record): string => $record->status_color)
                            ->icon(fn (NotificationOutbox $record): ?string => match (strtoupper((string) $record->status)) {
                                'PENDING' => 'heroicon-m-clock',
                                'SENDING' => 'heroicon-m-arrow-path',
                                'SENT' => 'heroicon-m-check-circle',
                                'FAILED' => 'heroicon-m-x-circle',
                                'CANCELLED' => 'heroicon-m-no-symbol',
                                default => null,
                            }),
                        TextEntry::make('type_label')
                            ->label('Classificação')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('recipient_email')
                            ->label('E-mail do Destinatário')
                            ->copyable(),
                        TextEntry::make('recipient_name')
                            ->label('Nome do Destinatário')
                            ->placeholder('—'),
                        TextEntry::make('subject')
                            ->label('Assunto')
                            ->columnSpanFull(),
                        TextEntry::make('template')
                            ->label('Template Utilizado')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('correlation_id')
                            ->label('ID de Correlação')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('attempts')
                            ->label('Tentativas')
                            ->state(fn (NotificationOutbox $record): string => "{$record->attempts} de {$record->max_attempts}"),
                        TextEntry::make('next_attempt_at')
                            ->label('Próxima Tentativa')
                            ->dateTime('d/m/Y · H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('sent_at')
                            ->label('Data de Envio')
                            ->dateTime('d/m/Y · H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Data de Registro')
                            ->dateTime('d/m/Y · H:i:s'),
                        TextEntry::make('last_error')
                            ->label('Último Erro Registrado')
                            ->placeholder('Nenhum erro registrado.')
                            ->color(fn (NotificationOutbox $record): ?string => $record->last_error ? 'danger' : null)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Dados Técnicos (Payload)')
                    ->icon('heroicon-o-code-bracket')
                    ->collapsible()
                    ->schema([
                        KeyValueEntry::make('payload_json')
                            ->label('Parâmetros Enviados')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
