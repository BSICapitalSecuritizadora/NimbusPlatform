<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Models\Emission;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class EmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações gerais')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome da operação/série')
                            ->required()
                            ->maxLength(255),

                        Select::make('type')
                            ->label('Tipo')
                            ->options(Emission::TYPE_OPTIONS)
                            ->required(),

                        TextInput::make('if_code')
                            ->label('Código IF')
                            ->maxLength(255),

                        TextInput::make('isin_code')
                            ->label('Código ISIN')
                            ->maxLength(255),

                        Select::make('status')
                            ->label('Status')
                            ->options(Emission::STATUS_OPTIONS)
                            ->default('draft')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Características')
                    ->schema([
                        TextInput::make('issuer')
                            ->label('Emissor')
                            ->maxLength(255),

                        TextInput::make('fiduciary_regime')
                            ->label('Regime fiduciário')
                            ->maxLength(255),

                        DatePicker::make('issue_date')
                            ->label('Data de emissão'),

                        DatePicker::make('maturity_date')
                            ->label('Data de vencimento'),

                        TextInput::make('monetary_update_period')
                            ->label('Período de atualização monetária')
                            ->maxLength(255),

                        TextInput::make('series')
                            ->label('Série')
                            ->maxLength(255),

                        TextInput::make('emission_number')
                            ->label('Número da emissão')
                            ->maxLength(255),

                        TextInput::make('issued_quantity')
                            ->label('Quantidade emitida')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 0)
                            JS))
                            ->stripCharacters(['.', ','])
                            ->minValue(0)
                            ->placeholder('32.600'),

                        TextInput::make('monetary_update_months')
                            ->label('Meses de atualização monetária')
                            ->maxLength(255),

                        TextInput::make('interest_payment_frequency')
                            ->label('Periodicidade de pagamento de juros')
                            ->maxLength(255),

                        TextInput::make('offer_type')
                            ->label('Tipo de oferta')
                            ->maxLength(255),

                        TextInput::make('concentration')
                            ->label('Concentração')
                            ->maxLength(255),

                        TextInput::make('issued_price')
                            ->label('Preço de emissão')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->prefix('R$')
                            ->placeholder('1.000,00'),

                        TextInput::make('amortization_frequency')
                            ->label('Periodicidade de amortização')
                            ->maxLength(255),

                        TextInput::make('integralized_quantity')
                            ->label('Quantidade integralizada')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 0)
                            JS))
                            ->stripCharacters(['.', ','])
                            ->minValue(0)
                            ->placeholder('13.200'),

                        TextInput::make('trustee_agent')
                            ->label('Agente fiduciário')
                            ->maxLength(255),

                        TextInput::make('debtor')
                            ->label('Devedor')
                            ->maxLength(255),

                        TextInput::make('remuneration')
                            ->label('Remuneração')
                            ->maxLength(255),

                        Toggle::make('prepayment_possibility')
                            ->label('Possibilidade de pré-pagamento')
                            ->default(false),

                        TextInput::make('segment')
                            ->label('Segmento')
                            ->maxLength(255),

                        TextInput::make('issued_volume')
                            ->label('Volume emitido')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->prefix('R$')
                            ->placeholder('32.000.000,00'),
                    ])
                    ->columns(2),

                Section::make('Publicação no site')
                    ->schema([
                        Toggle::make('is_public')
                            ->label('Disponível no site público')
                            ->default(false),

                        \Filament\Forms\Components\FileUpload::make('logo_path')
                            ->label('Logo da Operação')
                            ->image()
                            ->disk('public')
                            ->directory('emissions/logos')
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label('Descrição')
                            ->rows(6)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
