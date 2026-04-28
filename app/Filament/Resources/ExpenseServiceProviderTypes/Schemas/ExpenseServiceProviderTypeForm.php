<?php

namespace App\Filament\Resources\ExpenseServiceProviderTypes\Schemas;

use App\Models\ExpenseServiceProviderType;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExpenseServiceProviderTypeForm
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
                ->unique(ignoreRecord: true, table: ExpenseServiceProviderType::class)
                ->validationMessages([
                    'required' => 'Informe o nome do tipo de prestador de serviço.',
                    'unique' => 'Já existe um tipo de prestador de serviço cadastrado com este nome.',
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do tipo de prestador de serviço')
                ->schema(static::fields()),
        ]);
    }
}
