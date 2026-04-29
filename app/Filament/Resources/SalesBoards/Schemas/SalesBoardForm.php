<?php

namespace App\Filament\Resources\SalesBoards\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Construction;
use App\Models\SalesBoard;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;

class SalesBoardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do quadro de vendas')
                ->schema([
                    Select::make('emission_id')
                        ->label('Emissão')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state !== $old) {
                                $set('construction_id', null);
                            }
                        })
                        ->validationMessages([
                            'required' => 'Selecione a emissão.',
                        ]),

                    Select::make('construction_id')
                        ->label('Empreendimento')
                        ->relationship(
                            name: 'construction',
                            titleAttribute: 'development_name',
                            modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                                $get('emission_id'),
                                fn (Builder $query, mixed $emissionId): Builder => $query->where('emission_id', $emissionId),
                            ),
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabled(fn (Get $get): bool => blank($get('emission_id')))
                        ->helperText('Selecione primeiro a emissão para listar apenas os empreendimentos vinculados.')
                        ->rule(static function (Get $get): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if (blank($value) || blank($get('emission_id'))) {
                                    return;
                                }

                                $exists = Construction::query()
                                    ->whereKey($value)
                                    ->where('emission_id', $get('emission_id'))
                                    ->exists();

                                if (! $exists) {
                                    $fail('Selecione um empreendimento vinculado à emissão escolhida.');
                                }
                            };
                        })
                        ->validationMessages([
                            'required' => 'Selecione o empreendimento.',
                        ]),

                    TextInput::make('reference_month')
                        ->label('Competência')
                        ->placeholder('mm/aaaa')
                        ->mask('99/9999')
                        ->required()
                        ->formatStateUsing(fn (mixed $state): string => SalesBoard::formatReferenceMonthForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?string => SalesBoard::normalizeReferenceMonth($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?string => SalesBoard::normalizeReferenceMonth($state))
                        ->rule(static function (Get $get, ?SalesBoard $record = null): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                $referenceMonth = SalesBoard::normalizeReferenceMonth($value);

                                if (($referenceMonth === null) || blank($get('emission_id')) || blank($get('construction_id'))) {
                                    return;
                                }

                                $exists = SalesBoard::query()
                                    ->where('emission_id', $get('emission_id'))
                                    ->where('construction_id', $get('construction_id'))
                                    ->whereDate('reference_month', $referenceMonth)
                                    ->when($record?->exists, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('Já existe um quadro de vendas para esta emissão, empreendimento e competência.');
                                }
                            };
                        })
                        ->validationMessages([
                            'required' => 'Informe a competência no formato mm/aaaa.',
                        ]),
                ])
                ->columns(3),

            Section::make('Quantidades por status')
                ->schema([
                    static::quantityField('stock_units', 'Estoque'),
                    static::quantityField('financed_units', 'Financiado'),
                    static::quantityField('paid_units', 'Quitado'),
                    static::quantityField('exchanged_units', 'Permutado'),

                    TextInput::make('total_units')
                        ->label('Valor total')
                        ->disabled()
                        ->dehydrated(false)
                        ->default(0),
                ])
                ->columns(5),

            Section::make('Valores monetários por status')
                ->schema([
                    static::moneyField('stock_value', 'Valor total em estoque'),
                    static::moneyField('financed_value', 'Valor total financiado'),
                    static::moneyField('paid_value', 'Valor total quitado'),
                    static::moneyField('exchanged_value', 'Valor total permutado'),
                ])
                ->columns(2),
        ]);
    }

    protected static function quantityField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->numeric()
            ->integer()
            ->minValue(0)
            ->live(onBlur: true)
            ->afterStateUpdated(fn (Set $set, Get $get): null => self::fillTotalUnits($set, $get))
            ->validationMessages([
                'required' => "Informe o valor de {$label}.",
                'integer' => "Informe um número inteiro válido para {$label}.",
                'min' => "O valor de {$label} não pode ser negativo.",
            ]);
    }

    protected static function moneyField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
            ->minValue(0)
            ->placeholder('1.000,00')
            ->validationMessages([
                'required' => "Informe o {$label}.",
                'min' => "O {$label} não pode ser negativo.",
            ]);
    }

    protected static function fillTotalUnits(Set $set, Get $get): null
    {
        $set('total_units', self::calculateTotalUnitsFromState($get));

        return null;
    }

    protected static function calculateTotalUnitsFromState(Get $get): int
    {
        return self::normalizeIntegerValue($get('stock_units'))
            + self::normalizeIntegerValue($get('financed_units'))
            + self::normalizeIntegerValue($get('paid_units'))
            + self::normalizeIntegerValue($get('exchanged_units'));
    }

    protected static function normalizeIntegerValue(mixed $value): int
    {
        if (blank($value)) {
            return 0;
        }

        return (int) $value;
    }

    protected static function normalizeCurrencyValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && (trim($value) === '')) {
            return null;
        }

        return MoneyFormatter::normalizeDecimalValue($value);
    }

    protected static function formatCurrencyForDisplay(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && (trim($value) === '')) {
            return null;
        }

        return MoneyFormatter::formatCurrencyForDisplay($value);
    }
}
