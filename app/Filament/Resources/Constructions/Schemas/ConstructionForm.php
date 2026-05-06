<?php

namespace App\Filament\Resources\Constructions\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Construction;
use App\Models\ExpenseServiceProvider;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ConstructionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da obra')
                ->schema([
                    Select::make('emission_id')
                        ->label('Emissão')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a emissão.',
                        ]),

                    TextInput::make('development_name')
                        ->label('Empreendimento')
                        ->required()
                        ->maxLength(255)
                        ->validationMessages([
                            'required' => 'Informe o empreendimento.',
                        ]),

                    TextInput::make('development_cnpj')
                        ->label('CNPJ do empreendimento')
                        ->placeholder('00.000.000/0000-00')
                        ->mask('99.999.999/9999-99')
                        ->formatStateUsing(fn (?string $state): string => ExpenseServiceProvider::formatCnpj($state))
                        ->stripCharacters(['.', '/', '-'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => self::normalizeCnpj($state))
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => self::normalizeCnpj($state))
                        ->rule(static function (): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail): void {
                                if (blank($value)) {
                                    return;
                                }

                                if (strlen((string) $value) !== 14) {
                                    $fail('Informe um CNPJ válido com 14 dígitos.');
                                }
                            };
                        }),

                    TextInput::make('city')
                        ->label('Cidade')
                        ->required()
                        ->maxLength(255)
                        ->validationMessages([
                            'required' => 'Informe a cidade.',
                        ]),

                    Select::make('state')
                        ->label('Estado')
                        ->options(Construction::STATE_OPTIONS)
                        ->searchable()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione o estado.',
                        ]),

                    TextInput::make('construction_start_date')
                        ->label('Início da obra')
                        ->placeholder('mm/aaaa')
                        ->mask('99/9999')
                        ->formatStateUsing(fn (mixed $state): string => Construction::formatMonthForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?string => Construction::normalizeMonthDate($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?string => Construction::normalizeMonthDate($state)),

                    TextInput::make('construction_end_date')
                        ->label('Conclusão da obra')
                        ->placeholder('mm/aaaa')
                        ->mask('99/9999')
                        ->formatStateUsing(fn (mixed $state): string => Construction::formatMonthForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?string => Construction::normalizeMonthDate($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?string => Construction::normalizeMonthDate($state))
                        ->rule(static function (Get $get): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $startDate = Construction::normalizeMonthDate($get('construction_start_date'));
                                $endDate = Construction::normalizeMonthDate($value);

                                if (($startDate === null) || ($endDate === null)) {
                                    return;
                                }

                                if ($endDate < $startDate) {
                                    $fail('A conclusão da obra deve ser igual ou posterior ao início da obra.');
                                }
                            };
                        }),

                    TextInput::make('estimated_value')
                        ->label('Valor previsto')
                        ->prefix('R$')
                        ->inputMode('decimal')
                        ->mask(RawJs::make(<<<'JS'
                            $money($input, ',', '.')
                        JS))
                        ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->placeholder('1.000,00'),

                    Select::make('measurement_company_id')
                        ->label('Empresa de medição')
                        ->relationship(
                            name: 'measurementCompany',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->whereHas('type', fn (Builder $query): Builder => $query->where('name', Construction::MEASUREMENT_COMPANY_TYPE_NAME)),
                        )
                        ->searchable(['name', 'cnpj'])
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set, mixed $state): null => self::fillMeasurementCompanyCnpj($set, $state))
                        ->rule(static function (): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail): void {
                                if (blank($value)) {
                                    return;
                                }

                                $exists = ExpenseServiceProvider::query()
                                    ->whereKey($value)
                                    ->whereHas('type', fn (Builder $query): Builder => $query->where('name', Construction::MEASUREMENT_COMPANY_TYPE_NAME))
                                    ->exists();

                                if (! $exists) {
                                    $fail('Selecione uma empresa de medição do tipo Engenharia.');
                                }
                            };
                        })
                        ->validationMessages([
                            'required' => 'Selecione a empresa de medição.',
                        ]),

                    TextInput::make('measurement_company_cnpj')
                        ->label('CNPJ da empresa de medição')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (TextInput $component, ?Construction $record): void {
                            $component->state(ExpenseServiceProvider::formatCnpj($record?->measurementCompany?->cnpj));
                        }),
                ])
                ->columns(2),
        ]);
    }

    protected static function fillMeasurementCompanyCnpj(Set $set, mixed $state): null
    {
        $cnpj = blank($state)
            ? null
            : ExpenseServiceProvider::query()->find($state)?->cnpj;

        $set('measurement_company_cnpj', ExpenseServiceProvider::formatCnpj($cnpj));

        return null;
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

    protected static function normalizeCnpj(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $digits = Str::digitsOnly($value);

        return $digits === '' ? null : $digits;
    }
}
