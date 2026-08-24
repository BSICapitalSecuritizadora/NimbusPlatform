<?php

namespace App\Filament\Resources\FundTypes\Schemas;

use App\Models\FundType;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FundTypeForm
{
    /**
     * @return array<int, TextInput>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label('Nome')
                ->placeholder('Informe o nome do tipo de fundo')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true, table: FundType::class)
                ->columnSpanFull()
                ->validationMessages([
                    'required' => 'Informe o nome do tipo de fundo.',
                    'unique' => 'Já existe um tipo de fundo cadastrado com este nome.',
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do tipo de fundo')
                ->description('Defina o nome utilizado para classificar os fundos cadastrados.')
                ->schema(static::fields())
                ->columnSpanFull(),
        ]);
    }
}
