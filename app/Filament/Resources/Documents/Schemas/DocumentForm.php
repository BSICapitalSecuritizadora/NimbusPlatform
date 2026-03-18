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
use Illuminate\Support\Facades\Storage;
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
                    Hidden::make('storage_disk')
                        ->default(Document::defaultStorageDisk()),

                    FileUpload::make('file_path')
                        ->label('Arquivo')
                        ->required()
                        ->disk(fn (?Document $record): string => $record?->resolved_storage_disk ?? Document::defaultStorageDisk())
                        ->directory('documents')
                        ->openable()
                        ->downloadable()
                        ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                            $safe = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $file->getClientOriginalName());

                            return now()->format('Y/m/').uniqid().'_'.$safe;
                        })
                        ->afterStateUpdated(function ($state, callable $set): void {
                            if ($state instanceof TemporaryUploadedFile) {
                                $set('file_name', $state->getClientOriginalName());
                                $set('mime_type', $state->getMimeType());
                                $set('file_size', $state->getSize());
                                $set('storage_disk', Document::defaultStorageDisk());
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

                    Toggle::make('is_published')
                        ->label('Publicado')
                        ->helperText('Liberado para visualização no Portal do Investidor')
                        ->default(false)
                        ->live()
                        ->afterStateUpdated(function (bool $state, callable $set): void {
                            if (! $state) {
                                $set('is_public', false);
                            }
                        }),

                    Toggle::make('is_public')
                        ->label('Público')
                        ->helperText('Disponível no site público (exige também estar publicado)')
                        ->default(false)
                        ->live()
                        ->afterStateUpdated(function (bool $state, callable $set): void {
                            if ($state) {
                                $set('is_published', true);
                            }
                        })
                        ->rule(function ($get) {
                            return function (string $attribute, $value, \Closure $fail) use ($get): void {
                                if ($value && ! $get('is_published')) {
                                    $fail('Para marcar como Público, o documento precisa estar Publicado.');
                                }
                            };
                        }),
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
                                '<a href="'.Storage::disk($record->resolved_storage_disk)->url($record->file_path).'" target="_blank" class="text-primary-600 hover:underline">Abrir arquivo ↗</a>'
                            )
                            : '—'),
                ])
                ->columns(2)
                ->visibleOn('edit'),
        ]);
    }
}
