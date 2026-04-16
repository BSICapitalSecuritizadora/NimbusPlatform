<?php

namespace App\Filament\Resources\Payments\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do pagamento')
                ->schema([
                    Select::make('emission_id')
                        ->label('Emissão')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    DatePicker::make('payment_date')
                        ->label('Data do pagamento')
                        ->required(),

                    TextInput::make('premium_value')
                        ->label('Prêmio (R$)')
                        ->numeric()
                        ->default(0)
                        ->required(),

                    TextInput::make('interest_value')
                        ->label('Juros (R$)')
                        ->numeric()
                        ->default(0)
                        ->required(),

                    TextInput::make('amortization_value')
                        ->label('Amortização (R$)')
                        ->numeric()
                        ->default(0)
                        ->required(),

                    TextInput::make('extra_amortization_value')
                        ->label('Amortização extraordinária (R$)')
                        ->numeric()
                        ->default(0)
                        ->required(),
                ])
                ->columns(2),
        ]);
    }
}
