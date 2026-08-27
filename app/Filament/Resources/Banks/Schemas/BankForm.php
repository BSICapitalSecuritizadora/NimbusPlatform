<?php

namespace App\Filament\Resources\Banks\Schemas;

use App\Models\Bank;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

class BankForm
{
    /**
     * @return array<int, FileUpload|TextInput>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label('Nome do banco')
                ->placeholder('Ex: Banco Bradesco S.A.')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true, table: Bank::class)
                ->helperText('Denominação oficial utilizada na identificação e nos relatórios dos fundos vinculados.')
                ->validationMessages([
                    'required' => 'Informe o nome do banco.',
                    'unique' => 'Já existe um banco cadastrado com este nome.',
                ])
                ->columnSpanFull(),

            FileUpload::make('logo_path')
                ->label(fn (string $operation, ?Bank $record): string => ($operation === 'edit' && filled($record?->logo_path)) ? 'Substituir logotipo' : 'Logotipo institucional')
                ->helperText('Formatos aceitos: PNG, JPG ou SVG · Tamanho máximo: 2 MB.')
                ->image()
                ->acceptedFileTypes((array) config('uploads.logo.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml']))
                ->disk('public')
                ->directory('banks/logos')
                ->imagePreviewHeight('130')
                ->maxSize(2048)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn ($state): bool => filled($state))
                ->validationMessages([
                    'required' => 'Envie a logo do banco.',
                ])
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Dados da Instituição Bancária')
                    ->description('Informe a denominação e envie o logotipo oficial do banco.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome do banco')
                            ->placeholder('Ex: Banco Bradesco S.A.')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true, table: Bank::class)
                            ->helperText('Denominação oficial utilizada na identificação e nos relatórios dos fundos vinculados.')
                            ->validationMessages([
                                'required' => 'Informe o nome do banco.',
                                'unique' => 'Já existe um banco cadastrado com este nome.',
                            ])
                            ->extraFieldWrapperAttributes([
                                'class' => 'bsi-bank-name-field',
                            ])
                            ->columnSpanFull(),

                        Placeholder::make('visual_identity_header')
                            ->hiddenLabel()
                            ->content(new HtmlString('
                                <div class="bsi-bank-identity-header">
                                    <div class="bsi-bank-identity-title-row">
                                        <svg class="bsi-bank-identity-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <span class="bsi-bank-identity-title">Logotipo institucional</span>
                                    </div>
                                    <span class="bsi-bank-identity-subtitle">Identidade visual oficial para relatórios e extratos dos fundos</span>
                                </div>
                            '))
                            ->columnSpanFull(),

                        Placeholder::make('current_logo_preview')
                            ->hiddenLabel()
                            ->content(function (?Bank $record): ?HtmlString {
                                if (! $record || ! filled($record->logo_path)) {
                                    return null;
                                }

                                $disk = Storage::disk('public');
                                $exists = $disk->exists($record->logo_path);
                                $url = $disk->url($record->logo_path);
                                $fileSize = ($exists && ($size = $disk->size($record->logo_path))) ? Number::fileSize($size) : null;
                                $ext = strtoupper(pathinfo($record->logo_path, PATHINFO_EXTENSION) ?: 'PNG');
                                $metaText = $fileSize ? ($ext.' · '.$fileSize) : $ext;

                                return new HtmlString('
                                    <div class="bsi-bank-current-logo-card">
                                        <div class="bsi-bank-logo-stage-header">
                                            <span class="bsi-bank-stage-label">Logotipo atual</span>
                                            <span class="bsi-bank-status-badge">Ativo</span>
                                        </div>
                                        <div class="bsi-bank-logo-stage">
                                            <img src="'.e($url).'" alt="'.e($record->name ?? 'Logotipo').'" class="bsi-bank-logo-img" />
                                        </div>
                                        <div class="bsi-bank-logo-meta">
                                            <span class="bsi-bank-logo-meta-text">'.$metaText.'</span>
                                        </div>
                                    </div>
                                ');
                            })
                            ->visible(fn (?Bank $record): bool => filled($record?->logo_path))
                            ->columnSpanFull(),

                        FileUpload::make('logo_path')
                            ->label(fn (string $operation, ?Bank $record): string => ($operation === 'edit' && filled($record?->logo_path)) ? 'Substituir logotipo' : 'Upload do logotipo')
                            ->helperText('Formatos aceitos: PNG, JPG ou SVG · Tamanho máximo: 2 MB.')
                            ->image()
                            ->acceptedFileTypes((array) config('uploads.logo.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml']))
                            ->disk('public')
                            ->directory('banks/logos')
                            ->imagePreviewHeight('130')
                            ->maxSize(2048)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->validationMessages([
                                'required' => 'Envie a logo do banco.',
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
