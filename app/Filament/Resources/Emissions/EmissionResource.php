<?php

namespace App\Filament\Resources\Emissions;

use App\Filament\Resources\Emissions\Pages\CreateEmission;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ListEmissions;
use App\Models\Emission;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmissionResource extends Resource
{
    protected static ?string $model = Emission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Emissões';

    protected static ?string $modelLabel = 'Emissão';

    protected static ?string $pluralModelLabel = 'Emissões';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações gerais')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),

                        Select::make('type')
                            ->label('Tipo')
                            ->options([
                                'CR' => 'CR',
                                'CRA' => 'CRA',
                                'CRI' => 'CRI',
                            ])
                            ->required(),

                        TextInput::make('if_code')
                            ->label('Código IF')
                            ->maxLength(255),

                        TextInput::make('isin_code')
                            ->label('Código ISIN')
                            ->maxLength(255),

                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'draft' => 'Rascunho',
                                'active' => 'Ativa',
                                'closed' => 'Encerrada',
                            ])
                            ->default('draft')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Características')
                    ->schema([
                        TextInput::make('issuer')
                            ->label('Emissor'),

                        Select::make('fiduciary_regime')
                            ->label('Regime Fiduciário')
                            ->options([
                                'sim' => 'Sim',
                                'nao' => 'Não',
                            ]),

                        DatePicker::make('issue_date')
                            ->label('Data de emissão'),

                        DatePicker::make('maturity_date')
                            ->label('Data de vencimento'),

                        TextInput::make('monetary_update_period')
                            ->label('Período Atualização Monetária'),

                        TextInput::make('series')
                            ->label('Série'),

                        TextInput::make('emission_number')
                            ->label('Emissão'),

                        TextInput::make('issued_quantity')
                            ->label('Quantidade Emitida')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 0)
                            JS))
                            ->stripCharacters(['.', ','])
                            ->minValue(0)
                            ->placeholder('32.600'),

                        TextInput::make('monetary_update_months')
                            ->label('Meses Atualização Monetária'),

                        TextInput::make('interest_payment_frequency')
                            ->label('Periodicidade Pagamento Juros'),

                        TextInput::make('offer_type')
                            ->label('Oferta'),

                        TextInput::make('concentration')
                            ->label('Concentração'),

                        TextInput::make('issued_price')
                            ->label('Preço Emitido')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], (string) $state))
                            ->prefix('R$')
                            ->placeholder('1.000,00'),

                        TextInput::make('amortization_frequency')
                            ->label('Periodicidade Amortização'),

                        TextInput::make('integralized_quantity')
                            ->label('Quantidade Integralizada')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 0)
                            JS))
                            ->stripCharacters(['.', ','])
                            ->minValue(0)
                            ->placeholder('13.200'),

                        TextInput::make('trustee_agent')
                            ->label('Agente Fiduciário'),

                        TextInput::make('debtor')
                            ->label('Devedor'),

                        TextInput::make('remuneration')
                            ->label('Remuneração'),

                        Toggle::make('prepayment_possibility')
                            ->label('Possibilidade Pré-Pagamento'),

                        TextInput::make('segment')
                            ->label('Segmento'),

                        TextInput::make('issued_volume')
                            ->label('Volume Emitido')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => (float) str_replace(['.', ','], ['', '.'], (string) $state))
                            ->prefix('R$')
                            ->placeholder('32.000.000,00'),

                        Toggle::make('is_public')
                            ->label('Pública'),

                        Textarea::make('description')
                            ->label('Descrição')
                            ->rows(6)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),

                TextColumn::make('if_code')
                    ->label('Código IF')
                    ->toggleable(),

                TextColumn::make('isin_code')
                    ->label('Código ISIN')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('issuer')
                    ->label('Emissor')
                    ->toggleable(),

                TextColumn::make('series')
                    ->label('Série')
                    ->toggleable(),

                TextColumn::make('maturity_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('is_public')
                    ->label('Pública')
                    ->boolean(),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmissions::route('/'),
            'create' => CreateEmission::route('/create'),
            'edit' => EditEmission::route('/{record}/edit'),
        ];
    }
}