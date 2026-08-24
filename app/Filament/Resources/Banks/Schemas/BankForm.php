<?php

namespace App\Filament\Resources\Banks\Schemas;

use App\Models\Bank;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                ->columnSpanFull()
                ->validationMessages([
                    'required' => 'Informe o nome do banco.',
                    'unique' => 'Já existe um banco cadastrado com este nome.',
                ]),

            FileUpload::make('logo_path')
                ->label('Logotipo institucional')
                ->helperText('Envie uma imagem PNG, JPG ou SVG com proporção adequada.')
                ->image()
                ->acceptedFileTypes((array) config('uploads.logo.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml']))
                ->disk('public')
                ->directory('banks/logos')
                ->imagePreviewHeight('100')
                ->maxSize(2048)
                ->columnSpanFull()
                ->validationMessages([
                    'required' => 'Envie a logo do banco.',
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Instituição Bancária')
                ->description('Informe a denominação e envie o logotipo oficial do banco.')
                ->schema(static::fields())
                ->columnSpanFull(),
        ]);
    }
}
