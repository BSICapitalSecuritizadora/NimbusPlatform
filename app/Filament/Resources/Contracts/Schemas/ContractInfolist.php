<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Concerns\MoneyFormatter;
use App\Enums\ContractStatus;
use App\Models\Client;
use App\Models\Contract;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContractInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Situação')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (ContractStatus $state): string => $state->label())
                        ->color(fn (ContractStatus $state): string => $state->color()),

                    TextEntry::make('cancellation_date')
                        ->label('Data do Distrato')
                        ->date('d/m/Y')
                        ->color('danger')
                        ->visible(fn (Contract $record): bool => filled($record->cancellation_date)),

                    TextEntry::make('deleted_at')
                        ->label('Excluído em')
                        ->dateTime('d/m/Y H:i')
                        ->color('danger')
                        ->visible(fn (Contract $record): bool => $record->trashed()),
                ]),

            /**
             * Every buyer, never only the first: on the contract page the whole
             * set is the point. The document stays masked here as it always did
             * -- enough to tell two buyers apart, the full value lives in the
             * client record behind its own permission.
             */
            Section::make('Compradores')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('clients')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('name')
                                ->label('Nome / Razão Social')
                                ->weight('bold')
                                ->suffix(fn (Client $record): string => $record->trashed() ? ' (arquivado)' : ''),

                            TextEntry::make('document')
                                ->label(fn (Client $record): string => $record->person_type->documentLabel())
                                ->formatStateUsing(fn (?string $state): string => Client::maskDocument($state)),

                            TextEntry::make('person_type')
                                ->label('Tipo de Pessoa')
                                ->formatStateUsing(fn (mixed $state): string => $state?->label() ?? '—'),
                        ]),
                ]),

            Section::make('Unidade')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('construction.emission.name')
                        ->label('Emissão')
                        ->columnSpanFull(),

                    TextEntry::make('construction.development_name')
                        ->label('Empreendimento')
                        ->columnSpanFull(),

                    TextEntry::make('constructionUnit.block')
                        ->label('Bloco'),

                    TextEntry::make('constructionUnit.unit')
                        ->label('Unidade')
                        ->weight('bold'),
                ]),

            Section::make('Dados Comerciais')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('code')
                        ->label('Código do Contrato')
                        ->weight('bold')
                        ->copyable()
                        ->columnSpanFull(),

                    TextEntry::make('sale_date')
                        ->label('Data da Venda')
                        ->date('d/m/Y'),

                    TextEntry::make('sale_value')
                        ->label('Valor da Venda')
                        ->formatStateUsing(fn (mixed $state): string => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($state)),
                ]),

            self::installmentsSummarySection(),
        ]);
    }

    /**
     * Conference figures for the payment schedule, all read from one aggregate
     * query on the contract.
     *
     * The difference against the sale value is shown and nothing else happens:
     * an entrada paid before the contract, descontos, reforços, correção or a
     * schedule not fully imported yet all produce a legitimate gap. It is an
     * indicator for the reconciliation that comes later, never a rule, and no
     * data is corrected because of it.
     */
    private static function installmentsSummarySection(): Section
    {
        return Section::make('Parcelas')
            ->description('Totais calculados a partir das parcelas do contrato, desconsiderando as canceladas.')
            ->columnSpanFull()
            ->columns(4)
            ->visible(fn (Contract $record): bool => $record->installmentsSummary()['count'] > 0)
            ->schema([
                TextEntry::make('installments_expected_total')
                    ->label('Total Previsto nas Parcelas')
                    ->state(fn (Contract $record): string => self::money($record->installmentsSummary()['expected']))
                    ->helperText(fn (Contract $record): ?string => self::differenceHint($record)),

                TextEntry::make('installments_paid_total')
                    ->label('Total Recebido')
                    ->color('success')
                    ->state(fn (Contract $record): string => self::money($record->installmentsSummary()['paid'])),

                TextEntry::make('installments_outstanding_total')
                    ->label('Saldo das Parcelas')
                    ->color('warning')
                    ->state(fn (Contract $record): string => self::money($record->installmentsSummary()['outstanding'])),

                TextEntry::make('installments_count')
                    ->label('Parcelas')
                    ->state(fn (Contract $record): string => (string) $record->installmentsSummary()['count']),
            ]);
    }

    private static function differenceHint(Contract $record): ?string
    {
        $difference = $record->installmentsSummary()['difference'];

        if (abs($difference) < 0.01) {
            return null;
        }

        return sprintf(
            'Diferença em relação ao valor da venda: %s%s',
            $difference > 0 ? '+' : '-',
            self::money(abs($difference)),
        );
    }

    private static function money(float $value): string
    {
        return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value);
    }
}
