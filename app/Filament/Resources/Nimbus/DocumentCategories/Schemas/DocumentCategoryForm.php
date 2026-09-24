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
                    ->description('Defina como os documentos serão agrupados e identificados no módulo.')
                    ->icon('heroicon-o-bookmark-square')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome da Categoria')
                            ->placeholder('Ex.: Contratos, Regulamentos, Institucional')
                            ->helperText('Use um nome curto e objetivo para facilitar a identificação dos documentos.')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
