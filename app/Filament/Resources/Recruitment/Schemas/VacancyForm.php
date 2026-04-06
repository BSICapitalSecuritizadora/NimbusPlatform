<?php

namespace App\Filament\Resources\Recruitment\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class VacancyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informações da Vaga')
                ->schema([
                    TextInput::make('title')
                        ->label('Título da Vaga')
                        ->required()
                        ->lazy()
                        ->afterStateUpdated(fn (string $context, $state, $set) => $context === 'create' ? $set('slug', Str::slug($state)) : null),

                    TextInput::make('slug')
                        ->disabled()
                        ->required()
                        ->unique(ignoreRecord: true),

                    TextInput::make('department')
                        ->label('Departamento'),

                    TextInput::make('location')
                        ->label('Localização')
                        ->default('São Paulo, SP'),

                    Select::make('type')
                        ->label('Tipo de Contrato')
                        ->options([
                            'CLT' => 'CLT',
                            'PJ' => 'PJ',
                            'Estágio' => 'Estágio',
                            'Freelance' => 'Freelance',
                        ])
                        ->default('CLT'),

                    Toggle::make('is_active')
                        ->label('Vaga Ativa')
                        ->default(true),
                ])
                ->columns(2),

            Section::make('Conteúdo')
                ->schema([
                    RichEditor::make('description')
                        ->label('Descrição da Vaga')
                        ->required()
                        ->columnSpanFull(),

                    RichEditor::make('requirements')
                        ->label('Requisitos')
                        ->columnSpanFull(),

                    RichEditor::make('benefits')
                        ->label('Benefícios')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
