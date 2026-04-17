<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Filament\Resources\ExpenseServiceProviders\Schemas\ExpenseServiceProviderForm;
use App\Models\Expense;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da despesa')
                ->schema([
                    Select::make('emission_id')
                        ->label('Operação')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make('category')
                        ->label('Categoria')
                        ->options(Expense::CATEGORY_OPTIONS)
                        ->required(),

                    Select::make('expense_service_provider_id')
                        ->label('Prestador de serviço')
                        ->relationship('serviceProvider', 'name')
                        ->searchable(['name', 'cnpj'])
                        ->preload()
                        ->required()
                        ->helperText('Se o prestador ainda não estiver cadastrado, use a ação "Cadastrar prestador".')
                        ->createOptionForm(ExpenseServiceProviderForm::fields())
                        ->editOptionForm(ExpenseServiceProviderForm::fields())
                        ->createOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Cadastrar prestador')
                                ->modalHeading('Cadastrar prestador de serviço')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar prestador')
                                ->modalHeading('Editar prestador de serviço')
                                ->modalWidth('2xl'),
                        ),

                    TextInput::make('amount')
                        ->label('Valor')
                        ->required()
                        ->prefix('R$')
                        ->inputMode('decimal')
                        ->mask(RawJs::make(<<<'JS'
                            $money($input, ',', '.')
                        JS))
                        ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->validationMessages([
                            'required' => 'Informe o valor da despesa.',
                        ])
                        ->placeholder('1.000,00'),

                    Select::make('period')
                        ->label('Data / Período')
                        ->options(Expense::PERIOD_OPTIONS)
                        ->default(Expense::PERIOD_SINGLE)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if ($state === Expense::PERIOD_SINGLE) {
                                $set('end_date', null);
                            }
                        }),

                    DatePicker::make('start_date')
                        ->label('Data de início')
                        ->required(),

                    DatePicker::make('end_date')
                        ->label('Data de fim')
                        ->visible(fn (Get $get): bool => Expense::isRecurringPeriod($get('period')))
                        ->required(fn (Get $get): bool => Expense::isRecurringPeriod($get('period')))
                        ->afterOrEqual('start_date')
                        ->validationMessages([
                            'required' => 'Informe a data de fim para períodos recorrentes.',
                            'after_or_equal' => 'A data de fim deve ser igual ou posterior à data de início.',
                        ]),
                ])
                ->columns(2),
        ]);
    }

    protected static function normalizeCurrencyValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(['R$', ' '], '', $value);

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, '.')) {
            $parts = explode('.', $value);

            if ((count($parts) > 2) || (strlen((string) end($parts)) === 3)) {
                $value = str_replace('.', '', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } else {
            $value = str_replace(',', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    protected static function formatCurrencyForDisplay(mixed $value): ?string
    {
        $value = self::normalizeCurrencyValue($value);

        if ($value === null) {
            return null;
        }

        return number_format($value, 2, ',', '.');
    }
}
