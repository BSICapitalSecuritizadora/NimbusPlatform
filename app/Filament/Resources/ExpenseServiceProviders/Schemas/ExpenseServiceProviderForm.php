<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Schemas;

use App\Actions\Expenses\LookupExpenseServiceProviderCnpj;
use App\Filament\Resources\ExpenseServiceProviderTypes\Schemas\ExpenseServiceProviderTypeForm;
use App\Models\ExpenseServiceProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ExpenseServiceProviderForm
{
    /**
     * @return array<int, Select|TextInput>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('cnpj')
                ->label('CNPJ')
                ->placeholder('00.000.000/0000-00')
                ->mask('99.999.999/9999-99')
                ->formatStateUsing(fn (?string $state): string => ExpenseServiceProvider::formatCnpj($state))
                ->stripCharacters(['.', '/', '-'])
                ->required()
                ->rule('digits:14')
                ->unique(ignoreRecord: true)
                ->validationMessages([
                    'digits' => 'Informe um CNPJ válido com 14 dígitos.',
                    'unique' => 'Já existe um prestador cadastrado com este CNPJ.',
                ])
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    if (strlen(Str::digitsOnly((string) $state)) !== 14) {
                        return;
                    }

                    $result = app(LookupExpenseServiceProviderCnpj::class)->handle((string) $state);

                    if ($result['status'] !== 200) {
                        Notification::make()
                            ->title((string) ($result['payload']['error'] ?? 'Não foi possível consultar o CNPJ informado.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $set('name', (string) data_get($result, 'payload.data.name', ''));
                }),

            Select::make('expense_service_provider_type_id')
                ->label('Tipo')
                ->relationship('type', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->createOptionForm(ExpenseServiceProviderTypeForm::fields())
                ->editOptionForm(ExpenseServiceProviderTypeForm::fields())
                ->createOptionAction(
                    fn (Action $action): Action => $action
                        ->label('Cadastrar tipo')
                        ->modalHeading('Cadastrar tipo de prestador de serviço'),
                )
                ->editOptionAction(
                    fn (Action $action): Action => $action
                        ->label('Editar tipo')
                        ->modalHeading('Editar tipo de prestador de serviço'),
                )
                ->validationMessages([
                    'required' => 'Selecione o tipo do prestador de serviço.',
                ]),

            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do prestador')
                ->schema(static::fields())
                ->columns(2),
        ]);
    }
}
