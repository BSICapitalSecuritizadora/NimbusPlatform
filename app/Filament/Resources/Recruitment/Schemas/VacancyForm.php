<?php

namespace App\Filament\Resources\Recruitment\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class VacancyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações da Vaga')
                    ->schema([
                        TextInput::make('title')
                            ->label('Título da Vaga')
                            ->required()
                            ->lazy()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if (! filled($state)) {
                                    return;
                                }

                                $set('slug', Str::slug($state));
                            }),
                        TextInput::make('slug')
                            ->label('URL Amigável (Slug)')
                            ->disabled()
                            ->required()
                            ->unique(ignoreRecord: true),
                        TextInput::make('department')
                            ->label('Departamento / Área')
                            ->placeholder('Ex: Comercial'),
                        TextInput::make('location')
                            ->label('Localização')
                            ->default('São Paulo, SP')
                            ->placeholder('Ex: São Paulo, SP'),
                        Select::make('type')
                            ->label('Tipo de Contratação')
                            ->options([
                                'CLT' => 'CLT',
                                'PJ' => 'PJ',
                                'Estágio' => 'Estágio',
                                'Freelance' => 'Freelance',
                            ])
                            ->default('CLT'),
                        Toggle::make('is_active')
                            ->label('Vaga ativa para candidaturas')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Conteúdo da Vaga')
                    ->schema([
                        RichEditor::make('description')
                            ->label('Descrição')
                            ->required()
                            ->columnSpanFull(),
                        RichEditor::make('requirements')
                            ->label('Requisitos e Qualificações')
                            ->columnSpanFull(),
                        RichEditor::make('benefits')
                            ->label('Benefícios')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
