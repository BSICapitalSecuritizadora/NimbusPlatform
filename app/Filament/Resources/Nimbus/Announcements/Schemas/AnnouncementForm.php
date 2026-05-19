<?php

namespace App\Filament\Resources\Nimbus\Announcements\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make([
                    'default' => 1,
                ])
                    ->schema([
                        Section::make('Conteúdo do Aviso')
                            ->description('Comunicados exibidos aos usuários no portal.')
                            ->icon('heroicon-o-megaphone')
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('title')
                                    ->label('Título')
                                    ->required()
                                    ->maxLength(255),
                                Textarea::make('body')
                                    ->label('Mensagem')
                                    ->required()
                                    ->rows(6)
                                    ->columnSpanFull(),
                            ]),
                        Section::make('Publicação')
                            ->columnSpanFull()
                            ->schema([
                                Select::make('level')
                                    ->label('Nível')
                                    ->options([
                                        'info' => 'Informativo',
                                        'success' => 'Sucesso',
                                        'warning' => 'Atenção',
                                        'danger' => 'Crítico',
                                    ])
                                    ->default('info')
                                    ->required(),
                                DateTimePicker::make('starts_at')
                                    ->label('Início da Exibição')
                                    ->seconds(false)
                                    ->native(false),
                                DateTimePicker::make('ends_at')
                                    ->label('Fim da Exibição')
                                    ->seconds(false)
                                    ->native(false),
                                Toggle::make('is_active')
                                    ->label('Publicado no Portal')
                                    ->default(true)
                                    ->required(),
                            ]),
                    ]),
            ]);
    }
}
