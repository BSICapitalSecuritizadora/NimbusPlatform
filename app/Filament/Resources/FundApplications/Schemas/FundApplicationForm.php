<?php

namespace App\Filament\Resources\FundApplications\Schemas;

use App\Models\FundApplication;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FundApplicationForm
{
    /**
     * @return array<int, TextInput>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true, table: FundApplication::class)
                ->validationMessages([
                    'required' => 'Informe o nome da aplicação.',
                    'unique' => 'Já existe uma aplicação cadastrada com este nome.',
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da aplicação')
                ->schema(static::fields()),
        ]);
    }
}
