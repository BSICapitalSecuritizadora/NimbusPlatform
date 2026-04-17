<?php

namespace App\Filament\Resources\Funds\Schemas;

use App\Filament\Resources\Banks\Schemas\BankForm;
use App\Filament\Resources\FundApplications\Schemas\FundApplicationForm;
use App\Filament\Resources\FundNames\Schemas\FundNameForm;
use App\Filament\Resources\FundTypes\Schemas\FundTypeForm;
use App\Models\Fund;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class FundForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do fundo')
                ->schema([
                    Select::make('emission_id')
                        ->label('Operação')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a operação.',
                        ]),

                    Select::make('fund_type_id')
                        ->label('Tipo de fundo')
                        ->relationship('fundType', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state !== $old) {
                                $set('fund_name_id', null);
                            }
                        })
                        ->validationMessages([
                            'required' => 'Selecione o tipo de fundo.',
                        ])
                        ->createOptionForm(FundTypeForm::fields())
                        ->editOptionForm(FundTypeForm::fields())
                        ->createOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Cadastrar tipo')
                                ->modalHeading('Cadastrar tipo de fundo')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar tipo')
                                ->modalHeading('Editar tipo de fundo')
                                ->modalWidth('2xl'),
                        ),

                    Select::make('fund_name_id')
                        ->label('Nome do fundo')
                        ->relationship(
                            name: 'fundName',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                                $get('fund_type_id'),
                                fn (Builder $query, mixed $fundTypeId): Builder => $query->where('fund_type_id', $fundTypeId),
                            ),
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabled(fn (Get $get): bool => blank($get('fund_type_id')))
                        ->helperText('Selecione primeiro o tipo de fundo para listar apenas os nomes compatíveis.')
                        ->validationMessages([
                            'required' => 'Selecione o nome do fundo.',
                        ])
                        ->createOptionForm(fn (Get $get): array => FundNameForm::fields(
                            fundTypeId: self::normalizeSelectedId($get('fund_type_id')),
                            lockFundType: true,
                        ))
                        ->editOptionForm(fn (Get $get): array => FundNameForm::fields(
                            fundTypeId: self::normalizeSelectedId($get('fund_type_id')),
                            lockFundType: true,
                        ))
                        ->createOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Cadastrar nome')
                                ->modalHeading('Cadastrar nome do fundo')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar nome')
                                ->modalHeading('Editar nome do fundo')
                                ->modalWidth('2xl'),
                        ),

                    Select::make('fund_application_id')
                        ->label('Aplicação')
                        ->relationship('fundApplication', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a aplicação.',
                        ])
                        ->createOptionForm(FundApplicationForm::fields())
                        ->editOptionForm(FundApplicationForm::fields())
                        ->createOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Cadastrar aplicação')
                                ->modalHeading('Cadastrar aplicação')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar aplicação')
                                ->modalHeading('Editar aplicação')
                                ->modalWidth('2xl'),
                        ),

                    Select::make('bank_id')
                        ->label('Banco')
                        ->relationship('bank', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione o banco.',
                        ])
                        ->createOptionForm(BankForm::fields())
                        ->editOptionForm(BankForm::fields())
                        ->createOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Cadastrar banco')
                                ->modalHeading('Cadastrar banco')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar banco')
                                ->modalHeading('Editar banco')
                                ->modalWidth('2xl'),
                        ),

                    TextInput::make('agency')
                        ->label('Agência')
                        ->required()
                        ->maxLength(6)
                        ->mask('9999-9')
                        ->placeholder('1234-5')
                        ->rule('regex:/^\d{4}-\d$/')
                        ->validationMessages([
                            'required' => 'Informe a agência.',
                            'regex' => 'Informe a agência no formato 1234-5.',
                        ]),

                    TextInput::make('account')
                        ->label('Conta Corrente')
                        ->required()
                        ->maxLength(11)
                        ->mask(RawJs::make(<<<'JS'
                            (() => {
                                const digits = $input.replace(/\D/g, '');

                                if (digits.length <= 6) {
                                    return '99999-9';
                                }

                                if (digits.length <= 7) {
                                    return '999999-9';
                                }

                                if (digits.length <= 8) {
                                    return '9999999-9';
                                }

                                if (digits.length <= 9) {
                                    return '99999999-9';
                                }

                                return '999999999-9';
                            })()
                            JS))
                        ->placeholder('12345-6')
                        ->live(onBlur: true)
                        ->rule('regex:/^\d{5,9}-\d$/')
                        ->unique(
                            table: Fund::class,
                            column: 'account',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                ->where('emission_id', $get('emission_id'))
                                ->where('fund_application_id', $get('fund_application_id')),
                        )
                        ->validationMessages([
                            'required' => 'Informe a conta corrente.',
                            'regex' => 'Informe a conta corrente no formato 12345-6 até 123456789-0.',
                            'unique' => 'Já existe um fundo cadastrado com esta combinação de operação, aplicação e conta.',
                        ]),
                ])
                ->columns(2),
        ]);
    }

    protected static function normalizeSelectedId(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return (int) $value;
    }
}
