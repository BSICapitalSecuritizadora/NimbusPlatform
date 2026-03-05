<?php

namespace App\Filament\Resources\Documents\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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

                    Hidden::make('file_name'),
                    Hidden::make('mime_type'),
                    Hidden::make('file_size'),

                    FileUpload::make('file_path')
                        ->label('Arquivo')
                        ->required()
                        ->directory('documents')
                        ->preserveFilenames()
                        ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                            $safe = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $file->getClientOriginalName());

                            return time().'_'.$safe;
                        })
                        ->afterStateUpdated(function ($state, callable $set): void {
                            if ($state instanceof TemporaryUploadedFile) {
                                $set('file_name', $state->getClientOriginalName());
                                $set('mime_type', $state->getMimeType());
                                $set('file_size', $state->getSize());
                            }
                        }),

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