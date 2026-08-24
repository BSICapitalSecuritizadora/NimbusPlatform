<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\ClientPersonType;
use App\Models\Client;
use App\Rules\ClientDocument;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados Cadastrais')
                ->description('Identificação do comprador.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('person_type')
                        ->label('Tipo de Pessoa')
                        ->options(ClientPersonType::options())
                        ->default(ClientPersonType::Individual->value)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state !== $old) {
                                $set('document', null);
                            }
                        })
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Selecione o tipo de pessoa.',
                        ]),

                    TextInput::make('name')
                        ->label(fn (Get $get): string => self::personType($get)?->nameLabel() ?? 'Nome')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Informe o nome do cliente.',
                        ]),

                    TextInput::make('trade_name')
                        ->label('Nome Fantasia')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => self::personType($get) === ClientPersonType::Company)
                        ->columnSpanFull(),

                    TextInput::make('document')
                        ->label(fn (Get $get): string => self::personType($get)?->documentLabel() ?? 'CPF/CNPJ')
                        ->required()
                        ->mask(fn (Get $get): string => self::personType($get)?->documentMask() ?? '999.999.999-99')
                        ->placeholder(fn (Get $get): string => self::personType($get)?->documentMask() ?? '')
                        // Normalization happens on dehydration/validation below; stripCharacters()
                        // would also run on hydration and undo this formatting.
                        ->formatStateUsing(fn (?string $state): string => Client::formatDocument($state))
                        ->dehydrateStateUsing(fn (?string $state): ?string => Client::normalizeDocument($state))
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => Client::normalizeDocument($state))
                        ->rule(fn (Get $get, ?Client $record = null): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($get, $record): void {
                            (new ClientDocument(self::personType($get), $record?->getKey()))
                                ->validate($attribute, $value, $fail);
                        })
                        ->validationMessages([
                            'required' => 'Informe o CPF/CNPJ do cliente.',
                        ]),

                    TextInput::make('email')
                        ->label('E-mail')
                        ->email()
                        ->maxLength(255)
                        ->placeholder('cliente@email.com')
                        ->validationMessages([
                            'email' => 'Informe um e-mail válido.',
                        ]),

                    TextInput::make('phone')
                        ->label('Telefone')
                        ->tel()
                        ->mask('(99) 99999-9999')
                        ->formatStateUsing(fn (?string $state): string => Client::formatPhone($state))
                        ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : $state)
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => $state)
                        ->placeholder('(21) 99999-9999')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function personType(Get $get): ?ClientPersonType
    {
        $value = $get('person_type');

        return $value instanceof ClientPersonType
            ? $value
            : ClientPersonType::tryFrom((string) $value);
    }
}
