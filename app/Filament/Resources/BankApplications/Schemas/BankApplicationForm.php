<?php

namespace App\Filament\Resources\BankApplications\Schemas;

use App\Models\BankAccount;
use App\Models\BankApplication;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class BankApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Aplicação Bancária')
                ->description('Produto bancário dentro de uma conta física, identificado pelo código do produto informado pelo banco.')
                ->schema([
                    Select::make('bank_account_id')
                        ->label('Conta bancária')
                        ->placeholder('Selecione a conta...')
                        ->relationship('bankAccount', 'display_account')
                        ->getOptionLabelFromRecordUsing(fn (BankAccount $record): string => $record->optionLabel())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Uma conta pode abrigar várias aplicações. Cada código de produto é único dentro da sua conta.')
                        ->validationMessages([
                            'required' => 'Selecione a conta bancária.',
                        ])
                        ->columnSpanFull(),

                    ...static::fieldsForAccount(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Campos de identidade do produto, reutilizados pelo gerenciador de
     * aplicações da conta (que já fixa a conta dona).
     *
     * @return array<int, Select|TextInput>
     */
    public static function fieldsForAccount(?int $accountId = null): array
    {
        return [
            TextInput::make('codigo_produto')
                ->label('Código do produto')
                ->placeholder('Ex: 00042')
                ->required()
                ->maxLength(60)
                ->extraInputAttributes(['class' => 'font-mono'])
                ->unique(
                    table: BankApplication::class,
                    column: 'codigo_produto',
                    ignoreRecord: true,
                    modifyRuleUsing: function (Unique $rule, Get $get) use ($accountId): Unique {
                        $account = $accountId ?? $get('bank_account_id');

                        return $rule->where('bank_account_id', $account);
                    },
                )
                ->helperText('Código do produto informado pelo banco. Zeros à esquerda são preservados. Não confundir com a "Aplicação" da classificação do fundo.')
                ->validationMessages([
                    'required' => 'Informe o código do produto.',
                    'unique' => 'Esta conta já possui uma aplicação com este código de produto.',
                ]),

            TextInput::make('nome_produto')
                ->label('Nome do produto')
                ->placeholder('Ex: CDB DI (opcional)...')
                ->maxLength(255),

            Select::make('status')
                ->label('Status')
                ->options(BankApplication::STATUS_OPTIONS)
                ->default(BankApplication::STATUS_ACTIVE)
                ->required()
                ->validationMessages([
                    'required' => 'Selecione o status.',
                ])
                ->columnSpanFull(),
        ];
    }
}
