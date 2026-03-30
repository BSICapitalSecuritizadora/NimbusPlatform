<?php

namespace App\Filament\Resources\Nimbus\Announcements\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                Textarea::make('body')
                    ->required()
                    ->columnSpanFull(),
                Select::make('level')
                    ->options(['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'danger' => 'Danger'])
                    ->default('info')
                    ->required(),
                DateTimePicker::make('starts_at'),
                DateTimePicker::make('ends_at'),
                Toggle::make('is_active')
                    ->required(),
                TextInput::make('created_by_user_id')
                    ->required()
                    ->numeric(),
            ]);
    }
}
