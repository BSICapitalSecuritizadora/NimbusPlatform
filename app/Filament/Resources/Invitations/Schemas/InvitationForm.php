<?php

namespace App\Filament\Resources\Invitations\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class InvitationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->label('E-mail do convidado')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique('invitations', 'email', ignoreRecord: true)
                    ->placeholder('email@empresa.com.br'),
            ]);
    }
}
