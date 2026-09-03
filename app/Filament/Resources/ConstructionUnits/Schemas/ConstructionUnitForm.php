<?php

namespace App\Filament\Resources\ConstructionUnits\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\ConstructionUnit;
use App\Support\Money\IntegerMoney;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;

class ConstructionUnitForm
{
    /**
     * State path of the emission selector. It only narrows the construction
     * list: the unit belongs to the construction, which already carries the
     * emission, so nothing about it is persisted here.
     */
    public const EMISSION_FIELD = 'emission_id';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Unidade')
                ->description('Identificação da unidade dentro do empreendimento.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make(self::EMISSION_FIELD)
                        ->label('Emissão')
                        ->relationship('construction.emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->dehydrated(false)
                        ->live()
                        ->afterStateHydrated(function (Select $component, ?ConstructionUnit $record): void {
                            $component->state($record?->construction?->emission_id);
                        })
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state !== $old) {
                                $set('construction_id', null);
                            }
                        })
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Selecione a emissão.',
                        ]),

                    Select::make('construction_id')
                        ->label('Empreendimento')
                        ->relationship(
                            name: 'construction',
                            titleAttribute: 'development_name',
                            modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                                $get(self::EMISSION_FIELD),
                                fn (Builder $query, mixed $emissionId): Builder => $query->where('emission_id', $emissionId),
                            ),
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get): bool => blank($get(self::EMISSION_FIELD)))
                        ->helperText('Selecione primeiro a emissão para listar apenas os empreendimentos vinculados.')
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Selecione o empreendimento.',
                        ]),

                    TextInput::make('block')
                        ->label('Bloco')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->placeholder('01, A, Torre 01')
                        ->helperText('Informado exatamente como cadastrado, inclusive zeros à esquerda.')
                        ->validationMessages([
                            'required' => 'Informe o bloco.',
                        ]),

                    TextInput::make('unit')
                        ->label('Unidade')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->placeholder('305, A101, Loja 01, Cobertura 02')
                        ->rule(static fn (Get $get, ?ConstructionUnit $record = null): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($get, $record): void {
                            $block = ConstructionUnit::normalizeIdentifier((string) $get('block'));
                            $unit = ConstructionUnit::normalizeIdentifier((string) $value);

                            if (! ConstructionUnit::isDuplicate($get('construction_id'), $block, $unit, $record?->getKey())) {
                                return;
                            }

                            $fail("A unidade {$unit} do bloco {$block} já está cadastrada para este empreendimento.");
                        })
                        ->validationMessages([
                            'required' => 'Informe a unidade.',
                        ]),
                ]),

            self::baseValueSection(),
        ]);
    }

    /**
     * Valor de referência inicial da unidade.
     *
     * O par é indivisível: um valor sem data não é posicionável no tempo, e uma
     * data sem valor não posiciona nada. A regra é expressa com `requiredWith`
     * em vez de uma closure porque o validador do Laravel não executa regra de
     * closure em campo nulo -- exatamente o caso que precisa ser barrado aqui.
     *
     * Nenhum dos dois é obrigatório sozinho: a base já tem unidades cadastradas
     * antes de o valor existir, e exigi-lo agora impediria de editar o bloco de
     * uma unidade antiga só porque ninguém sabe quanto ela valia.
     */
    private static function baseValueSection(): Section
    {
        return Section::make('Valor Base')
            ->description('Referência comercial inicial da unidade. Reajustes posteriores são registrados no histórico de valores, sem sobrescrever esta referência.')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                TextInput::make('base_value')
                    ->label('Valor base')
                    ->prefix('R$')
                    ->inputMode('decimal')
                    ->mask(RawJs::make(<<<'JS'
                        $money($input, ',', '.')
                    JS))
                    ->formatStateUsing(fn (mixed $state): ?string => blank($state)
                        ? null
                        : MoneyFormatter::formatCurrencyForDisplay($state))
                    ->dehydrateStateUsing(fn (mixed $state): ?string => self::normalizeBaseValue($state))
                    ->mutateStateForValidationUsing(fn (mixed $state): ?string => self::normalizeBaseValue($state))
                    ->requiredWith('base_value_reference_date')
                    ->rule('numeric')
                    ->minValue(0)
                    ->placeholder('900.000,00')
                    ->helperText('Deixe em branco se o valor ainda não foi informado. Zero é um valor informado, não um valor ausente.')
                    ->extraInputAttributes(['class' => 'text-right font-mono tabular-nums'])
                    ->validationMessages([
                        'required_with' => 'Informe o valor base ou limpe a data de referência.',
                        'min' => 'O valor base não pode ser negativo.',
                    ]),

                DatePicker::make('base_value_reference_date')
                    ->label('Data de referência do valor base')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->requiredWith('base_value')
                    ->helperText('Data a partir da qual o valor base passou a valer.')
                    ->validationMessages([
                        'required_with' => 'Informe a data de referência ou limpe o valor base.',
                    ]),
            ]);
    }

    /**
     * Normaliza o valor digitado para decimal exato, sem passar por `float`.
     */
    private static function normalizeBaseValue(mixed $state): ?string
    {
        $cents = IntegerMoney::cents($state);

        return $cents === null ? null : IntegerMoney::decimalString($cents);
    }
}
