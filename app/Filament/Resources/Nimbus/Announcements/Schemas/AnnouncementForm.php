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
                    'xl' => 12,
                ])
                    ->schema([
                        Section::make('Conteúdo do aviso')
                            ->description('Comunicados exibidos para os usuários do portal.')
                            ->icon('heroicon-o-megaphone')
                            ->columnSpan([
                                'default' => 1,
                                'xl' => 8,
                            ])
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
                            ->columnSpan([
                                'default' => 1,
                                'xl' => 4,
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
                                    ->required(),
                                DateTimePicker::make('starts_at')
                                    ->label('Início de exibição')
                                    ->seconds(false)
                                    ->native(false),
                                DateTimePicker::make('ends_at')
                                    ->label('Fim de exibição')
                                    ->seconds(false)
                                    ->native(false),
                                Toggle::make('is_active')
                                    ->label('Ativo no portal')
                                    ->default(true)
                                    ->required(),
                            ]),
                    ]),
            ]);
    }
}
