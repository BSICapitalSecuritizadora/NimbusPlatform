<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments\Schemas;

use App\Models\Nimbus\PortalUser;
use App\Services\DocumentStorageService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class PortalDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        $maxKb = (int) config('uploads.document.max_kb', 102400);
        $maxMb = (int) ceil($maxKb / 1024);

        return $schema
            ->components([
                Grid::make([
                    'default' => 1,
                ])
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Dados do Documento')
                            ->description('Envie um documento específico para um usuário do portal, com título, descrição e arquivo.')
                            ->icon('heroicon-o-folder-open')
                            ->columnSpanFull()
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                                '3xl' => 2,
                            ])
                            ->schema([
                                Select::make('nimbus_portal_user_id')
                                    ->label('Usuário do Portal')
                                    ->placeholder('Selecione o usuário do portal')
                                    ->relationship('portalUser', 'full_name')
                                    ->getOptionLabelFromRecordUsing(fn (PortalUser $record): string => filled($record->email) ? "{$record->full_name} ({$record->email})" : $record->full_name)
                                    ->searchable(['full_name', 'email', 'document_number'])
                                    ->preload()
                                    ->required()
                                    ->columnSpan([
                                        'default' => 1,
                                        'md' => 1,
                                        '3xl' => 1,
                                    ]),

                                TextInput::make('title')
                                    ->label('Título')
                                    ->placeholder('Ex: Contrato Social Atualizado')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan([
                                        'default' => 1,
                                        'md' => 1,
                                        '3xl' => 1,
                                    ]),

                                Textarea::make('description')
                                    ->label('Descrição')
                                    ->placeholder('Informações adicionais sobre o documento destinadas ao usuário.')
                                    ->rows(3)
                                    ->columnSpanFull(),

                                FileUpload::make('file_path')
                                    ->label('Arquivo')
                                    ->required()
                                    ->disk(DocumentStorageService::privateDisk())
                                    ->directory(DocumentStorageService::PRIVATE_PREFIX.'/portal-documents')
                                    ->maxSize($maxKb)
                                    ->helperText("Formatos aceitos: PDF, DOCX, XLSX, PNG, JPG e ZIP. Tamanho máximo: {$maxMb} MB.")
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
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Informações do Arquivo')
                            ->icon('heroicon-o-document-text')
                            ->columnSpanFull()
                            ->columns([
                                'default' => 1,
                                'md' => 3,
                            ])
                            ->schema([
                                Placeholder::make('file_original_name_display')
                                    ->label('Arquivo Atual')
                                    ->content(fn ($record): string => $record?->file_original_name ?? '—')
                                    ->visibleOn('edit'),
                                Placeholder::make('file_size_display')
                                    ->label('Tamanho')
                                    ->content(fn ($record): string => $record?->file_size ? Number::fileSize($record->file_size, 1) : '—')
                                    ->visibleOn('edit'),
                                Placeholder::make('file_mime_display')
                                    ->label('Tipo de Arquivo')
                                    ->content(fn ($record): string => $record?->file_mime ?? '—')
                                    ->visibleOn('edit'),
                            ]),
                    ]),
            ]);
    }
}
