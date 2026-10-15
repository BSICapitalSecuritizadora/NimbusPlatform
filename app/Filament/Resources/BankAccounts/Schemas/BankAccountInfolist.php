<?php

namespace App\Filament\Resources\BankAccounts\Schemas;

use App\Models\BankAccount;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BankAccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação da Conta')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('bank.name')
                        ->label('Banco')
                        ->weight('semibold')
                        ->placeholder('—'),

                    TextEntry::make('agency')
                        ->label('Agência')
                        ->fontFamily('mono')
                        ->state(fn (BankAccount $record): string => $record->formattedAgency()),

                    TextEntry::make('account')
                        ->label('Conta')
                        ->fontFamily('mono')
                        ->state(fn (BankAccount $record): string => $record->formattedAccount()),

                    TextEntry::make('holder_context')
                        ->label('Contexto do titular')
                        ->placeholder('—')
                        ->columnSpanFull(),

                    TextEntry::make('notes')
                        ->label('Observações')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Section::make('Integração Conta Azul')
                ->columnSpanFull()
                ->columns(1)
                ->schema([
                    TextEntry::make('conta_azul_account_id')
                        ->label('ID da conta no Conta Azul')
                        ->fontFamily('mono')
                        ->placeholder('Não configurado')
                        ->badge(fn (BankAccount $record): bool => filled($record->conta_azul_account_id))
                        ->color(fn (BankAccount $record): string => filled($record->conta_azul_account_id) ? 'success' : 'gray'),

                    TextEntry::make('conta_azul_note')
                        ->hiddenLabel()
                        ->state('Configuração conceitual: a sincronização automática de despesas continua operando exclusivamente pelos IDs cadastrados em cada fundo. Configurar este ID não ativa a integração em nível de conta nem atribui despesas a operações.')
                        ->color('gray'),
                ]),

            Section::make('Vínculos')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('bank_applications_count')
                        ->label('Aplicações bancárias')
                        ->state(fn (BankAccount $record): int => $record->bankApplications()->count())
                        ->badge(),

                    TextEntry::make('linked_funds_count')
                        ->label('Fundos vinculados')
                        ->state(fn (BankAccount $record): int => (clone $record->linkedFundQuery())->distinct()->count('funds.id'))
                        ->badge()
                        ->helperText('Inclui fundos vinculados diretamente e por aplicação.'),

                    TextEntry::make('linked_emissions_count')
                        ->label('Operações atendidas')
                        ->state(fn (BankAccount $record): int => $record->linkedEmissions()->count())
                        ->badge()
                        ->helperText('Compartilhar a conta entre operações é esperado e não caracteriza aplicação compartilhada.'),

                    TextEntry::make('linked_emissions')
                        ->label('Operações vinculadas')
                        ->state(fn (BankAccount $record): string => $record->linkedEmissions()->pluck('name')->implode(', '))
                        ->placeholder('Nenhuma operação vinculada.')
                        ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
