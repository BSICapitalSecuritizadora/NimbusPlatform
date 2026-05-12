<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\Document;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
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
                        ->options(Document::CATEGORY_OPTIONS)
                        ->required()
                        ->searchable(),

                    Hidden::make('file_name'),
                    Hidden::make('mime_type'),
                    Hidden::make('file_size'),
                    Hidden::make('storage_disk'),

                    FileUpload::make('file_path')
                        ->label('Arquivo')
                        ->required()
                        ->disk('local')
                        ->visibility('private')
                        ->directory('documents')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->maxSize((int) config('uploads.document.max_kb', 102400))
                        ->helperText('Tamanho máximo por arquivo: '.(int) ceil(config('uploads.document.max_kb', 102400) / 1024).' MB.')
                        ->afterStateUpdated(function ($state, callable $set) {
                            if ($state instanceof TemporaryUploadedFile) {
                                $set('file_name', $state->getClientOriginalName());
                                $set('mime_type', $state->getMimeType());
                                $set('file_size', $state->getSize());
                                $set('storage_disk', 'local');
                            }
                        })
                        ->columnSpanFull(),

                    Select::make('emissions')
                        ->label('Série')
                        ->relationship('emissions', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->placeholder('Selecione uma ou mais séries')
                        ->required(false),

                    /**
                     * Workflow
                     */
                    Toggle::make('is_published')
                        ->label('Publicado')
                        ->helperText('Quando marcado, o documento fica disponível no portal, respeitando as permissões configuradas.')
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get) {
                            // se despublicar, não pode continuar público
                            if (! $state && $get('is_public')) {
                                $set('is_public', false);
                            }
                        })
                        ->default(false),

                    Toggle::make('is_public')
                        ->label('Público')
                        ->helperText('Quando marcado, o documento aparece no site público (somente se estiver publicado).')
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get) {
                            // se tornar público, força publicado
                            if ($state && ! $get('is_published')) {
                                $set('is_published', true);
                            }
                        })
                        ->default(false),
                ])
                ->columns(2),

            Section::make('Informações do arquivo')
                ->schema([
                    Placeholder::make('file_name_display')
                        ->label('Nome do arquivo')
                        ->content(fn ($record): string => $record?->file_name ?? '—'),

                    Placeholder::make('mime_type_display')
                        ->label('Tipo')
                        ->content(fn ($record): string => $record?->mime_type ?? '—'),

                    Placeholder::make('file_size_display')
                        ->label('Tamanho')
                        ->content(fn ($record): string => $record?->file_size
                            ? Number::fileSize($record->file_size)
                            : '—'),

                    Placeholder::make('storage_disk_display')
                        ->label('Disco')
                        ->content(fn ($record): string => $record?->resolved_storage_disk ?? Document::defaultStorageDisk()),

                    Placeholder::make('published_at_display')
                        ->label('Publicado em')
                        ->content(fn ($record): string => $record?->published_at?->format('d/m/Y H:i') ?? '—'),

                    Placeholder::make('published_by_display')
                        ->label('Publicado por')
                        ->content(fn ($record): string => $record?->publisher?->name ?? '—'),

                    Placeholder::make('download_link')
                        ->label('Link')
                        ->content(fn ($record) => $record?->file_path
                            ? new HtmlString(
                                '<a href="'.route('admin.documents.download', $record).'" target="_blank" class="text-primary-600 hover:underline">Baixar arquivo ↗</a>'
                            )
                            : '—'),
                ])
                ->columns(2)
                ->visibleOn('edit'),
        ]);
    }
}
