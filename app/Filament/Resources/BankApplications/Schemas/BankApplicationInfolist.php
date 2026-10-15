<?php

namespace App\Filament\Resources\BankApplications\Schemas;

use App\Filament\Resources\FundAllocations\FundAllocationResource;
use App\Filament\Resources\FundAllocations\Schemas\FundAllocationDraftLines;
use App\Models\BankApplication;
use App\Models\BankApplicationBalanceSnapshot;
use App\Services\Funds\FundAllocationService;
use App\Support\Funds\FundAllocationMoney;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BankApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Aplicação Bancária')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('bankAccount.optionLabel')
                        ->label('Conta bancária')
                        ->state(fn (BankApplication $record): string => $record->bankAccount->optionLabel())
                        ->weight('semibold')
                        ->columnSpanFull(),

                    TextEntry::make('codigo_produto')
                        ->label('Código do produto')
                        ->fontFamily('mono')
                        ->weight('semibold'),

                    TextEntry::make('nome_produto')
                        ->label('Nome do produto')
                        ->placeholder('—'),

                    TextEntry::make('status')
                        ->label('Status')
                        ->state(fn (BankApplication $record): string => $record->statusLabel())
                        ->badge()
                        ->color(fn (BankApplication $record): string => $record->status === BankApplication::STATUS_ACTIVE ? 'success' : 'gray')
                        ->columnSpanFull(),
                ]),

            Section::make('Uso Compartilhado')
                ->columnSpanFull()
                ->columns(1)
                ->schema([
                    TextEntry::make('shared')
                        ->label('Situação')
                        ->state(fn (BankApplication $record): string => $record->isShared() ? 'Compartilhado' : 'Uso exclusivo')
                        ->badge()
                        ->color(fn (BankApplication $record): string => $record->isShared() ? 'warning' : 'gray'),

                    TextEntry::make('shared_note')
                        ->hiddenLabel()
                        ->state(fn (BankApplication $record): string => $record->isShared()
                            ? 'Esta aplicação física está vinculada a fundos de mais de uma operação. O saldo exibido em cada fundo é o valor manual legado daquele fundo — não é uma parcela de alocação aprovada, e nunca representa o saldo total da aplicação.'
                            : 'Esta aplicação atende a uma única operação até o momento. O indicador passa a "Compartilhado" quando fundos de mais de uma operação forem vinculados.')
                        ->color('gray'),

                    TextEntry::make('shared_emissions')
                        ->label('Operações atendidas')
                        ->state(fn (BankApplication $record): string => $record->sharedEmissions()->pluck('name')->implode(', '))
                        ->placeholder('Nenhuma operação vinculada.')
                        ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false),
                ]),

            Section::make('Alocação por competência')
                ->description('Saldo de referência manual mais recente e atalho para a distribuição mensal entre os fundos.')
                ->columnSpanFull()
                ->columns(2)
                ->visible(fn (): bool => auth()->user()?->can(FundAllocationService::PERMISSION_VIEW) ?? false)
                ->schema([
                    TextEntry::make('latest_reference_balance')
                        ->label('Saldo de referência manual mais recente')
                        ->state(function (BankApplication $record): string {
                            $snapshot = BankApplicationBalanceSnapshot::query()
                                ->where('bank_application_id', $record->getKey())
                                ->orderByDesc('competence')
                                ->orderByDesc('version')
                                ->first();

                            if (! $snapshot instanceof BankApplicationBalanceSnapshot) {
                                return 'Sem saldo de referência registrado.';
                            }

                            try {
                                $amount = FundAllocationDraftLines::formatCents(
                                    FundAllocationMoney::toCents((string) $snapshot->amount)
                                );
                            } catch (\InvalidArgumentException) {
                                $amount = '—';
                            }

                            $competence = $snapshot->competence?->format('m/Y') ?? '—';

                            return "{$amount} · {$competence} · v{$snapshot->version}";
                        })
                        ->fontFamily('mono'),

                    TextEntry::make('allocation_periods_link')
                        ->label('Distribuição mensal')
                        ->state(fn (): string => 'Abrir alocações por competência')
                        ->url(fn (): string => FundAllocationResource::getUrl('index'))
                        ->openUrlInNewTab(false)
                        ->color('primary')
                        ->weight('semibold'),
                ]),
        ]);
    }
}
