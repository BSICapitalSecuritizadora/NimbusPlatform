<?php

namespace App\Filament\Resources\BankAccounts\Schemas;

use App\Filament\Resources\Banks\Schemas\BankForm;
use App\Models\BankAccount;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(static::fields());
    }

    /**
     * Campos do cadastro, reutilizados pelo cadastro de fundos (que permite
     * criar a conta global sem sair do fluxo do fundo).
     *
     * @return array<int, Section>
     */
    public static function fields(): array
    {
        return [
            Section::make('Identificação da Conta')
                ->description('Conta física global: pode atender a várias operações sem duplicar o saldo bancário.')
                ->schema([
                    Select::make('bank_id')
                        ->label('Banco')
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
                        )
                        ->columnSpanFull(),

                    TextInput::make('agency_number')
                        ->label('Agência (número)')
                        ->placeholder('1234')
                        ->required()
                        ->maxLength(6)
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->rule('regex:/^\d{1,6}$/')
                        ->helperText('Somente dígitos (1 a 6). Zeros à esquerda são preservados.')
                        ->validationMessages([
                            'required' => 'Informe o número da agência.',
                            'regex' => 'Informe de 1 a 6 dígitos para a agência.',
                        ])
                        ->columnSpan(3),

                    TextInput::make('agency_digit')
                        ->label('Dígito')
                        ->placeholder('5')
                        ->required()
                        ->maxLength(2)
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->rule('regex:/^[0-9Xx]$/')
                        ->validationMessages([
                            'required' => 'Informe o dígito da agência.',
                            'regex' => 'O dígito da agência deve ser um único caractere (0-9 ou X).',
                        ])
                        ->columnSpan(1),

                    TextInput::make('account_number')
                        ->label('Conta (número)')
                        ->placeholder('0012345')
                        ->required()
                        ->maxLength(12)
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->rule('regex:/^\d{4,12}$/')
                        ->unique(
                            table: BankAccount::class,
                            column: 'account_number',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                ->where('bank_id', $get('bank_id'))
                                ->where('agency_number', $get('agency_number'))
                                ->where('agency_digit', mb_strtoupper((string) $get('agency_digit')))
                                ->where('account_digit', mb_strtoupper((string) $get('account_digit'))),
                        )
                        ->helperText('Somente dígitos (4 a 12). Zeros à esquerda são preservados.')
                        ->validationMessages([
                            'required' => 'Informe o número da conta.',
                            'regex' => 'Informe de 4 a 12 dígitos para a conta.',
                            'unique' => 'Já existe uma conta bancária cadastrada com estes dados (banco, agência e conta).',
                        ])
                        ->columnSpan(3),

                    TextInput::make('account_digit')
                        ->label('Dígito')
                        ->placeholder('6')
                        ->required()
                        ->maxLength(2)
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->rule('regex:/^[0-9Xx]$/')
                        ->validationMessages([
                            'required' => 'Informe o dígito da conta.',
                            'regex' => 'O dígito da conta deve ser um único caractere (0-9 ou X).',
                        ])
                        ->columnSpan(1),
                ])
                ->columns(4)
                ->columnSpanFull(),

            Section::make('Contexto e Integração')
                ->description('Informações descritivas e identificador conceitual de integração.')
                ->schema([
                    TextInput::make('holder_context')
                        ->label('Contexto do titular')
                        ->placeholder('Ex: Patrimônio Separado (opcional)...')
                        ->maxLength(255)
                        ->helperText('Descrição livre. Não participa da identidade da conta.'),

                    TextInput::make('conta_azul_account_id')
                        ->label('ID da conta no Conta Azul (conceitual)')
                        ->placeholder('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->helperText('Configuração conceitual: apenas registra o ID. A sincronização automática continua operando exclusivamente pelos IDs cadastrados em cada fundo, e este valor nunca é copiado para os fundos vinculados.')
                        ->unique(table: BankAccount::class, column: 'conta_azul_account_id', ignoreRecord: true)
                        ->validationMessages([
                            'unique' => 'Este ID já está configurado em outra conta bancária.',
                        ]),

                    Textarea::make('notes')
                        ->label('Observações')
                        ->placeholder('Anotações operacionais sobre esta conta (opcional)...')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];
    }
}
