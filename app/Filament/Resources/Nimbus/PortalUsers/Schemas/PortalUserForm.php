<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PortalUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make([
                    'default' => 1,
                    'xl' => 12,
                ])
                    ->schema([
                        Section::make('Dados Cadastrais')
                            ->description('Registro de novo usuário externo')
                            ->icon(Heroicon::OutlinedIdentification)
                            ->columnSpan([
                                'default' => 1,
                                'xl' => 8,
                            ])
                            ->columns([
                                'default' => 1,
                                '2xl' => 2,
                            ])
                            ->schema([
                                TextInput::make('full_name')
                                    ->label('Nome')
                                    ->placeholder('Ex: João da Silva')
                                    ->prefixIcon(Heroicon::OutlinedUser)
                                    ->required()
                                    ->maxLength(200)
                                    ->columnSpanFull(),
                                TextInput::make('email')
                                    ->label('E-mail')
                                    ->email()
                                    ->placeholder('Ex: joao@email.com')
                                    ->prefixIcon(Heroicon::OutlinedEnvelope)
                                    ->maxLength(200)
                                    ->columnSpanFull(),
                                TextInput::make('document_number')
                                    ->label('CPF')
                                    ->placeholder('000.000.000-00')
                                    ->prefixIcon(Heroicon::OutlinedIdentification)
                                    ->mask('999.999.999-99')
                                    ->formatStateUsing(fn (?string $state): ?string => self::formatCpfForDisplay($state))
                                    ->dehydrateStateUsing(fn (?string $state): ?string => self::normalizeDigits($state))
                                    ->rule('regex:/^\d{3}\.\d{3}\.\d{3}-\d{2}$/')
                                    ->validationMessages([
                                        'regex' => 'Use o formato xxx.xxx.xxx-xx.',
                                    ])
                                    ->maxLength(20),
                                TextInput::make('phone_number')
                                    ->label('Telefone/Celular')
                                    ->placeholder('(00) 00000-0000')
                                    ->prefixIcon(Heroicon::OutlinedPhone)
                                    ->tel()
                                    ->mask('(99) 99999-9999')
                                    ->formatStateUsing(fn (?string $state): ?string => self::formatPhoneForDisplay($state))
                                    ->dehydrateStateUsing(fn (?string $state): ?string => self::normalizeDigits($state))
                                    ->rule('regex:/^\(\d{2}\)\s\d{4,5}-\d{4}$/')
                                    ->validationMessages([
                                        'regex' => 'Use o formato (xx) xxxx-xxxx ou (xx) xxxxx-xxxx.',
                                    ])
                                    ->maxLength(20),
                            ]),
                        Section::make('Status da Conta')
                            ->icon(Heroicon::OutlinedShieldCheck)
                            ->compact()
                            ->columnSpan([
                                'default' => 1,
                                'xl' => 4,
                            ])
                            ->schema([
                                Select::make('status')
                                    ->label('Situação')
                                    ->options([
                                        'ACTIVE' => 'Ativo',
                                        'INACTIVE' => 'Inativo',
                                        'BLOCKED' => 'Suspenso',
                                    ])
                                    ->default('ACTIVE')
                                    ->afterStateHydrated(function (?string $state, Set $set): void {
                                        if ($state === 'INVITED') {
                                            $set('status', 'INACTIVE');
                                        }
                                    })
                                    ->required()
                                    ->native(false)
                                    ->helperText('Estado atual do acesso ao portal.'),
                            ]),
                    ]),
            ]);
    }

    private static function normalizeDigits(?string $state): ?string
    {
        if (! filled($state)) {
            return null;
        }

        return preg_replace('/\D+/', '', $state);
    }

    private static function formatCpfForDisplay(?string $state): ?string
    {
        $digits = self::normalizeDigits($state);

        if (! filled($digits) || strlen($digits) !== 11) {
            return $state;
        }

        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits);
    }

    private static function formatPhoneForDisplay(?string $state): ?string
    {
        $digits = self::normalizeDigits($state);

        if (! filled($digits)) {
            return $state;
        }

        if (strlen($digits) === 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $digits);
        }

        if (strlen($digits) === 11) {
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $digits);
        }

        return $state;
    }
}
