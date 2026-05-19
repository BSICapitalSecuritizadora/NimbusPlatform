<?php

namespace App\Filament\Resources\Nimbus\DocumentCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações da Categoria')
                    ->description('Classificação utilizada para a organização dos documentos do módulo.')
                    ->icon('heroicon-o-bookmark-square')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome da Categoria')
                            ->placeholder('Ex: Contratos, Regulamentos, Institucional')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100),
                    ]),
            ]);
    }
}
