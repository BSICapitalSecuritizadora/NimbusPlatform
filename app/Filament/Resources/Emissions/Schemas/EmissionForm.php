<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Models\Emission;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
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
                Section::make('Dados Identificadores')
                    ->schema([
                        TextInput::make('name')
                            ->label('Denominação da Operação')
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
                            ->label('Status da Operação')
                            ->options(Emission::STATUS_OPTIONS)
                            ->default('draft')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Atributos da Emissão')
                    ->schema([
                        TextInput::make('issuer')
                            ->label('Emissor')
                            ->maxLength(255),

                        TextInput::make('lead_coordinator')
                            ->label('Coordenador Líder')
                            ->maxLength(255),

                        TextInput::make('fiduciary_regime')
                            ->label('Regime Fiduciário')
                            ->maxLength(255),

                        DatePicker::make('issue_date')
                            ->label('Data de Emissão'),

                        DatePicker::make('maturity_date')
                            ->label('Data de Vencimento'),

                        TextInput::make('monetary_update_period')
                            ->label('Periodicidade de Atualização Monetária')
                            ->maxLength(255),

                        TextInput::make('series')
                            ->label('Série')
                            ->maxLength(255),

                        TextInput::make('emission_number')
                            ->label('Número da Emissão')
                            ->maxLength(255),

                        TextInput::make('issued_quantity')
                            ->label('Quantidade Emitida')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 0)
                            JS))
                            ->stripCharacters(['.', ','])
                            ->minValue(0)
                            ->placeholder('32.600'),

                        TextInput::make('monetary_update_months')
                            ->label('Ciclo de Atualização Monetária (Meses)')
                            ->maxLength(255),

                        TextInput::make('interest_payment_frequency')
                            ->label('Fluxo de Pagamento de Juros')
                            ->maxLength(255),

                        TextInput::make('offer_type')
                            ->label('Modalidade de Oferta')
                            ->maxLength(255),

                        TextInput::make('concentration')
                            ->label('Nível de Concentração')
                            ->maxLength(255),

                        TextInput::make('issued_price')
                            ->label('Preço de Emissão')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->prefix('R$')
                            ->placeholder('1.000,00'),

                        TextInput::make('amortization_frequency')
                            ->label('Fluxo de Amortização')
                            ->maxLength(255),

                        TextInput::make('integralized_quantity')
                            ->label('Quantidade Integralizada')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 0)
                            JS))
                            ->stripCharacters(['.', ','])
                            ->minValue(0)
                            ->placeholder('13.200'),

                        TextInput::make('trustee_agent')
                            ->label('Agente Fiduciário')
                            ->maxLength(255),

                        TextInput::make('debtor')
                            ->label('Devedor')
                            ->maxLength(255),

                        TextInput::make('remuneration')
                            ->label('Taxa de Remuneração')
                            ->maxLength(255),

                        Toggle::make('prepayment_possibility')
                            ->label('Opção de Resgate Antecipado')
                            ->default(false),

                        TextInput::make('segment')
                            ->label('Segmento de Atuação')
                            ->maxLength(255),

                        TextInput::make('issued_volume')
                            ->label('Volume Emitido')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->prefix('R$')
                            ->placeholder('32.000.000,00'),

                        TextInput::make('current_pu')
                            ->label('Preço Unitário (PU) Atual')
                            ->mask(\Filament\Support\RawJs::make(<<<'JS'
                                $money($input, ',', '.', 6)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 6, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->prefix('R$'),

                        TextInput::make('integralization_status')
                            ->label('Status de Integralização')
                            ->placeholder('Ex: 100% ou 50.000 cotas')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Divulgação Institucional')
                    ->schema([
                        Toggle::make('is_public')
                            ->label('Divulgação em Ambiente Público')
                            ->default(false),

                        FileUpload::make('logo_path')
                            ->label('Identidade Visual da Operação')
                            ->image()
                            ->disk(Emission::defaultStorageDisk())
                            ->visibility('public')
                            ->directory('emissions/logos')
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label('Notas Institucionais / Sumário')
                            ->rows(6)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
