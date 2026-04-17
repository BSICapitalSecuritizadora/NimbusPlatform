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
                ->label('Nome')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true, table: Bank::class)
                ->validationMessages([
                    'required' => 'Informe o nome do banco.',
                    'unique' => 'Já existe um banco cadastrado com este nome.',
                ]),

            FileUpload::make('logo_path')
                ->label('Logo')
                ->image()
                ->disk('public')
                ->directory('banks/logos')
                ->required()
                ->columnSpanFull()
                ->validationMessages([
                    'required' => 'Envie a logo do banco.',
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do banco')
                ->schema(static::fields())
                ->columns(2),
        ]);
    }
}
