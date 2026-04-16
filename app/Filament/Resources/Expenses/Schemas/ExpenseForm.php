<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Filament\Resources\ExpenseServiceProviders\Schemas\ExpenseServiceProviderForm;
use App\Models\Expense;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

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
}
