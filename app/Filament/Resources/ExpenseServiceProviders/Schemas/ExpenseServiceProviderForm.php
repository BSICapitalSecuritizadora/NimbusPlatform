<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Schemas;

use App\Actions\Expenses\LookupExpenseServiceProviderCnpj;
use App\Models\ExpenseServiceProvider;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ExpenseServiceProviderForm
{
    /**
     * @return array<int, TextInput>
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

            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),
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
