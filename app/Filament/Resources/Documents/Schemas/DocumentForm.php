<?php

namespace App\Filament\Resources\Documents\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do documento')
                ->schema([
                    TextInput::make('title')
                        ->label('Título')
                        ->required()
                        ->maxLength(255),

                    Select::make('category')
                        ->label('Categoria')
                        ->options([
                            'anuncios' => 'Anúncios',
                            'assembleias' => 'Assembleias',
                            'convocacoes_assembleias' => 'Convocações para Assembleias',
                            'demonstracoes_financeiras' => 'Demonstrações Financeiras',
                            'documentos_operacao' => 'Documentos da Operação',
                            'fatos_relevantes' => 'Fatos Relevantes',
                            'relatorios_anuais' => 'Relatórios Anuais',
                        ])
                        ->required()
                        ->searchable(),

                    FileUpload::make('file_path')
                        ->label('Arquivo')
                        ->required()
                        ->directory('documents')
                        ->preserveFilenames(),

                    Select::make('emissions')
                        ->label('Série')
                        ->relationship('emissions', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->placeholder('Selecione uma ou mais séries')
                        ->required(false),

                    Toggle::make('is_published')
                        ->label('Publicado')
                        ->default(false),

                    Toggle::make('is_public')
                        ->label('Público')
                        ->default(false),
                ])
                ->columns(2),
        ]);
    }
}