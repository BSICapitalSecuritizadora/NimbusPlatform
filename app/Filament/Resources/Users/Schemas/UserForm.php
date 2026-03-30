<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('cargo')
                    ->label('Cargo')
                    ->maxLength(255),
                TextInput::make('departamento')
                    ->label('Departamento')
                    ->maxLength(255),
                Placeholder::make('approved_at')
                    ->label('Aprovado em')
                    ->content(fn ($record) => $record?->approved_at?->format('d/m/Y H:i') ?? 'Aguardando aprovação'),
            ]);
    }
}
