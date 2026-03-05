<?php

namespace App\Filament\Resources\Documents\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                            'relatorio' => 'Relatório',
                            'ata' => 'Ata',
                            'contrato' => 'Contrato',
                            'comunicado' => 'Comunicado',
                            'outro' => 'Outro',
                        ])
                        ->searchable(),

                    FileUpload::make('file_path')
                        ->label('Arquivo')
                        ->required()
                        ->directory('documents')
                        ->preserveFilenames(),

                    TextInput::make('file_name')
                        ->label('Nome do arquivo')
                        ->maxLength(255),

                    TextInput::make('mime_type')
                        ->label('Tipo MIME')
                        ->maxLength(255),

                    TextInput::make('file_size')
                        ->label('Tamanho do arquivo')
                        ->numeric(),

                    Toggle::make('is_public')
                        ->label('Público')
                        ->default(false),
                ])
                ->columns(2),
        ]);
    }
}