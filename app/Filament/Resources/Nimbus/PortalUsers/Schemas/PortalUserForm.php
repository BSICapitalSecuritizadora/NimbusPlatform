<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PortalUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('full_name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('document_number'),
                TextInput::make('phone_number')
                    ->tel(),
                TextInput::make('external_id'),
                Textarea::make('notes')
                    ->columnSpanFull(),
                Select::make('status')
                    ->options([
                        'INVITED' => 'I n v i t e d',
                        'ACTIVE' => 'A c t i v e',
                        'INACTIVE' => 'I n a c t i v e',
                        'BLOCKED' => 'B l o c k e d',
                    ])
                    ->default('INVITED')
                    ->required(),
                DateTimePicker::make('last_login_at'),
                TextInput::make('last_login_method'),
            ]);
    }
}
