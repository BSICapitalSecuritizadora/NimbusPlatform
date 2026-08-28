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
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Conteúdo do Aviso')
                            ->description('Comunicados exibidos aos usuários no portal.')
                            ->icon('heroicon-o-megaphone')
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('title')
                                    ->label('Título')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Ex: Manutenção Programada do Sistema'),
                                Textarea::make('body')
                                    ->label('Mensagem')
                                    ->required()
                                    ->rows(6)
                                    ->placeholder('Descreva o comunicado que será exibido no portal...')
                                    ->columnSpanFull(),
                            ]),
                        Section::make('Publicação')
                            ->description('Defina a criticidade, vigência e disponibilidade do aviso.')
                            ->icon('heroicon-o-calendar')
                            ->columnSpanFull()
                            ->columns([
                                'default' => 1,
                                'md' => 3,
                            ])
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
                                    ->required()
                                    ->columnSpan([
                                        'default' => 1,
                                        'md' => 1,
                                    ]),
                                DateTimePicker::make('starts_at')
                                    ->label('Início da Exibição')
                                    ->seconds(false)
                                    ->native(false)
                                    ->columnSpan([
                                        'default' => 1,
                                        'md' => 1,
                                    ]),
                                DateTimePicker::make('ends_at')
                                    ->label('Fim da Exibição')
                                    ->seconds(false)
                                    ->native(false)
                                    ->columnSpan([
                                        'default' => 1,
                                        'md' => 1,
                                    ]),
                                Toggle::make('is_active')
                                    ->label('Publicado no Portal')
                                    ->helperText('Quando desativado, o aviso não será exibido aos usuários.')
                                    ->default(true)
                                    ->required()
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }
}
