<?php

namespace App\Filament\Resources\Nimbus\Submissions\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nimbus_portal_user_id')
                    ->required()
                    ->numeric(),
                TextInput::make('reference_code')
                    ->required(),
                TextInput::make('submission_type')
                    ->required()
                    ->default('REGISTRATION'),
                TextInput::make('title')
                    ->required(),
                Textarea::make('message')
                    ->columnSpanFull(),
                TextInput::make('responsible_name'),
                TextInput::make('company_cnpj'),
                TextInput::make('company_name'),
                TextInput::make('main_activity'),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('website')
                    ->url(),
                TextInput::make('net_worth')
                    ->numeric(),
                TextInput::make('annual_revenue')
                    ->numeric(),
                Toggle::make('is_us_person')
                    ->required(),
                Toggle::make('is_pep')
                    ->required(),
                TextInput::make('shareholder_data'),
                TextInput::make('registrant_name'),
                TextInput::make('registrant_position'),
                TextInput::make('registrant_rg'),
                TextInput::make('registrant_cpf'),
                Select::make('status')
                    ->options([
                        'PENDING' => 'P e n d i n g',
                        'UNDER_REVIEW' => 'U n d e r  r e v i e w',
                        'COMPLETED' => 'C o m p l e t e d',
                        'REJECTED' => 'R e j e c t e d',
                    ])
                    ->default('PENDING')
                    ->required(),
                TextInput::make('created_ip'),
                TextInput::make('created_user_agent'),
                DateTimePicker::make('submitted_at')
                    ->required(),
                DateTimePicker::make('status_updated_at'),
                TextInput::make('status_updated_by')
                    ->numeric(),
            ]);
    }
}
