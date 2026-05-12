<?php

namespace App\Filament\Resources\Nimbus\GeneralDocuments\Schemas;

use App\Services\DocumentStorageService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class GeneralDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make([
                    'default' => 1,
                    '2xl' => 12,
                ])
                    ->schema([
                        Section::make('Dados da publicação')
                            ->description('Cadastro e manutenção da biblioteca institucional.')
                            ->icon('heroicon-o-folder')
                            ->columnSpan([
                                'default' => 1,
                                '2xl' => 8,
                            ])
                            ->columns([
                                'default' => 1,
                                '3xl' => 2,
                            ])
                            ->schema([
                                Select::make('nimbus_category_id')
                                    ->label('Categoria')
                                    ->relationship('category', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->columnSpanFull(),
                                TextInput::make('title')
                                    ->label('Título')
                                    ->placeholder('Ex: Regulamento Interno 2026')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                Textarea::make('description')
                                    ->label('Descrição')
                                    ->placeholder('Resumo do conteúdo e da finalidade do documento.')
                                    ->rows(4)
                                    ->columnSpanFull(),
                                Hidden::make('file_original_name'),
                                Hidden::make('file_size'),
                                Hidden::make('file_mime'),
                                FileUpload::make('file_path')
                                    ->label('Arquivo')
                                    ->required()
                                    ->disk(DocumentStorageService::PRIVATE_DISK)
                                    ->directory(DocumentStorageService::PRIVATE_PREFIX.'/general-documents')
                                    ->preserveFilenames()
                                    ->maxSize((int) config('uploads.document.max_kb', 102400))
                                    ->helperText('Tamanho máximo por arquivo: '.(int) ceil(config('uploads.document.max_kb', 102400) / 1024).' MB.')
                                    ->acceptedFileTypes([
                                        'application/pdf',
                                        'application/msword',
                                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                        'application/vnd.ms-excel',
                                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                        'image/jpeg',
                                        'image/png',
                                        'application/zip',
                                    ])
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        if (! $state instanceof TemporaryUploadedFile) {
                                            return;
                                        }

                                        $set('file_original_name', $state->getClientOriginalName());
                                        $set('file_size', $state->getSize());
                                        $set('file_mime', $state->getMimeType());
                                    })
                                    ->columnSpanFull(),
                            ]),
                        Section::make('Disponibilidade')
                            ->icon('heroicon-o-document-text')
                            ->columnSpan([
                                'default' => 1,
                                '2xl' => 4,
                            ])
                            ->schema([
                                Toggle::make('is_active')
                                    ->label('Disponível no portal')
                                    ->default(true)
                                    ->required()
                                    ->helperText('Quando ativo, o documento pode ser disponibilizado aos usuários.')
                                    ->columnSpanFull(),
                                DateTimePicker::make('published_at')
                                    ->label('Publicado em')
                                    ->seconds(false)
                                    ->native(false)
                                    ->helperText('Se vazio, o documento fica sem data definida de publicação.')
                                    ->columnSpanFull(),
                                Placeholder::make('file_original_name_display')
                                    ->label('Arquivo atual')
                                    ->content(fn ($record): string => $record?->file_original_name ?? '—')
                                    ->visibleOn('edit'),
                                Placeholder::make('file_size_display')
                                    ->label('Tamanho')
                                    ->content(fn ($record): string => $record?->file_size ? Number::fileSize($record->file_size) : '—')
                                    ->visibleOn('edit'),
                                Placeholder::make('file_mime_display')
                                    ->label('Tipo do arquivo')
                                    ->content(fn ($record): string => $record?->file_mime ?? '—')
                                    ->visibleOn('edit'),
                            ]),
                    ]),
            ]);
    }
}
