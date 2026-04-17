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
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true, table: FundType::class)
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
                ->schema(static::fields()),
        ]);
    }
}
