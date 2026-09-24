<?php

namespace App\Filament\Resources\Funds\Schemas;

use App\Concerns\MoneyFormatter;
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
    /**
     * Marcador dos selects pesquisáveis Operação, Tipo de fundo, Nome do fundo e
     * Aplicação de "Classificação" e Banco de "Dados Bancários". O tema libera o popup
     * dos wrappers recortados da página, junto com o bloco dos Responsáveis pelo Fluxo
     * da operação; nenhum outro campo o recebe.
     */
    public const CLASSIFICATION_SELECT_CLASS = 'bsi-fund-classification-select';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Classificação')
                ->description('Defina a operação e as características institucionais do fundo.')
                ->schema([
                    Select::make('emission_id')
                        ->label('Operação')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione a operação...')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a operação.',
                        ]),

                    Select::make('fund_type_id')
                        ->label('Tipo de fundo')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione o tipo de fundo...')
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
                                ->tooltip('Cadastrar tipo de fundo')
                                ->modalHeading('Cadastrar tipo de fundo')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar tipo')
                                ->tooltip('Editar tipo de fundo')
                                ->modalHeading('Editar tipo de fundo')
                                ->modalWidth('2xl'),
                        ),

                    Select::make('fund_name_id')
                        ->label('Nome do fundo')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione o nome do fundo...')
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
                                ->tooltip('Cadastrar nome do fundo')
                                ->modalHeading('Cadastrar nome do fundo')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar nome')
                                ->tooltip('Editar nome do fundo')
                                ->modalHeading('Editar nome do fundo')
                                ->modalWidth('2xl'),
                        ),

                    Select::make('fund_application_id')
                        ->label('Aplicação')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione a aplicação...')
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
                                ->tooltip('Cadastrar aplicação')
                                ->modalHeading('Cadastrar aplicação')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar aplicação')
                                ->tooltip('Editar aplicação')
                                ->modalHeading('Editar aplicação')
                                ->modalWidth('2xl'),
                        ),

                    TextInput::make('trade_name')
                        ->label('Nome fantasia')
                        ->placeholder('Informe o nome fantasia ou denominação comercial (opcional)...')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('Dados Bancários')
                ->description('Informações da instituição financeira, agência, conta e integração técnica.')
                ->schema([
                    Select::make('bank_id')
                        ->label('Banco')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione o banco...')
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
                                ->tooltip('Cadastrar banco')
                                ->modalHeading('Cadastrar banco')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar banco')
                                ->tooltip('Editar banco')
                                ->modalHeading('Editar banco')
                                ->modalWidth('2xl'),
                        ),

                    TextInput::make('agency')
                        ->label('Agência')
                        ->required()
                        ->maxLength(6)
                        ->mask('9999-9')
                        ->placeholder('1234-5')
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->rule('regex:/^\d{4}-\d$/')
                        ->validationMessages([
                            'required' => 'Informe a agência.',
                            'regex' => 'Informe a agência no formato 1234-5.',
                        ]),

                    TextInput::make('account')
                        ->label('Conta Corrente')
                        ->required()
                        ->maxLength(11)
                        ->extraInputAttributes(['class' => 'font-mono'])
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

                    TextInput::make('conta_azul_account_id')
                        ->label('ID da conta no Conta Azul')
                        ->placeholder('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->helperText('UUID da conta financeira correspondente integrada no Conta Azul.')
                        ->unique(table: Fund::class, column: 'conta_azul_account_id', ignoreRecord: true)
                        ->validationMessages([
                            'unique' => 'Este ID já está vinculado a outro fundo.',
                        ])
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Saldos e Limites')
                ->description('Controle financeiro do saldo atual e valor mínimo exigido para notificações e relatórios.')
                ->schema([
                    TextInput::make('balance')
                        ->label('Saldo')
                        ->required()
                        ->prefix('R$')
                        ->inputMode('decimal')
                        ->extraInputAttributes(['class' => 'font-mono text-right tabular-nums'])
                        ->mask(RawJs::make(<<<'JS'
                            $money($input, ',', '.')
                        JS))
                        ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->validationMessages([
                            'required' => 'Informe o saldo do fundo.',
                        ])
                        ->placeholder('0,00')
                        ->helperText('Atualize o saldo no primeiro dia de cada mês. O valor do mês anterior será salvo automaticamente no histórico de saldo. Se o saldo ficar abaixo do valor mínimo, um alerta será exibido e enviado por e-mail aos investidores vinculados.'),

                    TextInput::make('minimum_balance')
                        ->label('Valor mínimo')
                        ->prefix('R$')
                        ->inputMode('decimal')
                        ->extraInputAttributes(['class' => 'font-mono text-right tabular-nums'])
                        ->mask(RawJs::make(<<<'JS'
                            $money($input, ',', '.')
                        JS))
                        ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->helperText('Piso financeiro operacional exigido para monitoramento de conformidade.')
                        ->placeholder('0,00'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    protected static function normalizeSelectedId(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
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
