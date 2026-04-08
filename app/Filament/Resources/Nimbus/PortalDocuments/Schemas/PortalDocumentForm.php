<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments\Schemas;

use App\Models\Nimbus\PortalUser;
use App\Services\DocumentStorageService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class PortalDocumentForm
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
                        Section::make('Dados do documento')
                            ->description('Upload individualizado para um usuário do portal.')
                            ->icon('heroicon-o-folder-open')
                            ->columnSpan([
                                'default' => 1,
                                '2xl' => 8,
                            ])
                            ->columns([
                                'default' => 1,
                                '3xl' => 2,
                            ])
                            ->schema([
                                Select::make('nimbus_portal_user_id')
                                    ->label('Usuário do portal')
                                    ->relationship('portalUser', 'full_name')
                                    ->getOptionLabelFromRecordUsing(fn (PortalUser $record): string => filled($record->email) ? "{$record->full_name} ({$record->email})" : $record->full_name)
                                    ->searchable(['full_name', 'email'])
                                    ->preload()
                                    ->required()
                                    ->columnSpanFull(),
                                TextInput::make('title')
                                    ->label('Título')
                                    ->placeholder('Ex: Contrato Social Atualizado')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                Textarea::make('description')
                                    ->label('Descrição')
                                    ->placeholder('Informações adicionais para o usuário sobre este arquivo.')
                                    ->rows(4)
                                    ->columnSpanFull(),
                                Hidden::make('file_original_name'),
                                Hidden::make('file_size'),
                                Hidden::make('file_mime'),
                                FileUpload::make('file_path')
                                    ->label('Arquivo')
                                    ->required()
                                    ->disk(DocumentStorageService::PRIVATE_DISK)
                                    ->directory(DocumentStorageService::PRIVATE_PREFIX.'/portal-documents')
                                    ->preserveFilenames()
                                    ->maxSize(51200)
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
                        Section::make('Informações do arquivo')
                            ->icon('heroicon-o-document-text')
                            ->columnSpan([
                                'default' => 1,
                                '2xl' => 4,
                            ])
                            ->schema([
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
