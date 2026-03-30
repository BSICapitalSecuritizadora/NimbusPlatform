<?php

namespace App\Filament\Resources\Nimbus\GeneralDocuments\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class GeneralDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nimbus_category_id')
                    ->required()
                    ->numeric(),
                TextInput::make('title')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('file_path')
                    ->required(),
                TextInput::make('file_original_name')
                    ->required(),
                TextInput::make('file_size')
                    ->required()
                    ->numeric(),
                TextInput::make('file_mime')
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
                DateTimePicker::make('published_at'),
                TextInput::make('created_by_user_id')
                    ->numeric(),
            ]);
    }
}
