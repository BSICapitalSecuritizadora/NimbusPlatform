<?php

namespace App\Filament\Resources\SalesBoards\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Reading of a sales board as an evolution: how the construction entered the
 * operation, where it stands today, and -- through the history relation manager
 * below -- everything that happened in between.
 */
class SalesBoardInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Operação')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('emission.name')
                        ->label('Operação')
                        ->weight('bold'),
                    TextEntry::make('construction.development_name')
                        ->label('Empreendimento')
                        ->weight('bold'),
                    TextEntry::make('emission.status')
                        ->label('Status da Operação')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => Emission::STATUS_OPTIONS[$state] ?? (string) $state)
                        ->color(fn (?string $state): string => match ($state) {
                            'draft' => 'gray',
                            'active' => 'success',
                            'default' => 'danger',
                            'closed' => 'danger',
                            default => 'gray',
                        }),
                ]),

            Grid::make(['default' => 1, 'xl' => 2])
                ->columnSpanFull()
                ->schema([
                    static::initialPositionSection(),
                    static::currentPositionSection(),
                ]),
        ]);
    }

    protected static function initialPositionSection(): Section
    {
        return Section::make('Início da Operação')
            ->description('Posição inicial da operação, consolidada quando a emissão deixou o status "Em Elaboração". Imutável.')
            ->icon('heroicon-o-lock-closed')
            ->columns(2)
            ->schema([
                TextEntry::make('initial_position_consolidated_at')
                    ->label('Consolidado em')
                    ->state(fn (SalesBoard $record): string => $record->initialPosition?->created_at?->format('d/m/Y H:i')
                        ?? 'Será consolidada quando a emissão deixar "Em Elaboração".')
                    ->color(fn (SalesBoard $record): string => $record->hasInitialPosition() ? 'gray' : 'warning')
                    ->columnSpanFull(),

                ...static::positionEntries(
                    'initial',
                    fn (SalesBoard $record): ?SalesBoardHistory => $record->initialPosition,
                ),
            ]);
    }

    protected static function currentPositionSection(): Section
    {
        return Section::make('Posição Atual')
            ->description('Última posição registrada para este empreendimento.')
            ->icon('heroicon-o-chart-bar')
            ->columns(2)
            ->schema([
                TextEntry::make('current_position_updated_at')
                    ->label('Atualizado em')
                    ->state(fn (SalesBoard $record): string => $record->updated_at?->format('d/m/Y H:i') ?? '—')
                    ->columnSpanFull(),

                TextEntry::make('reference_month')
                    ->label('Competência')
                    ->state(fn (SalesBoard $record): string => SalesBoard::formatReferenceMonthForDisplay($record->reference_month))
                    ->badge(),
                TextEntry::make('total_units')
                    ->label('Quantidade Total')
                    ->state(fn (SalesBoard $record): string => (string) $record->total_units)
                    ->weight('bold'),
                TextEntry::make('stock_units')->label('Estoque'),
                TextEntry::make('financed_units')->label('Financiado'),
                TextEntry::make('paid_units')->label('Quitado'),
                TextEntry::make('exchanged_units')->label('Permutado'),
                TextEntry::make('stock_value')
                    ->label('Valor em Estoque')
                    ->state(fn (SalesBoard $record): string => static::money($record->stock_value)),
                TextEntry::make('financed_value')
                    ->label('Valor Financiado')
                    ->state(fn (SalesBoard $record): string => static::money($record->financed_value)),
                TextEntry::make('paid_value')
                    ->label('Valor Quitado')
                    ->state(fn (SalesBoard $record): string => static::money($record->paid_value)),
                TextEntry::make('exchanged_value')
                    ->label('Valor Permutado')
                    ->state(fn (SalesBoard $record): string => static::money($record->exchanged_value)),
            ]);
    }

    protected static function money(mixed $value): string
    {
        return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value);
    }

    /**
     * Same indicators as the current position, so both blocks line up visually.
     *
     * @param  Closure(SalesBoard): ?SalesBoardHistory  $resolvePosition
     * @return array<int, TextEntry>
     */
    protected static function positionEntries(string $prefix, Closure $resolvePosition): array
    {
        $units = [
            'reference_month' => 'Competência',
            'total_units' => 'Quantidade Total',
            'stock_units' => 'Estoque',
            'financed_units' => 'Financiado',
            'paid_units' => 'Quitado',
            'exchanged_units' => 'Permutado',
        ];

        $values = [
            'stock_value' => 'Valor em Estoque',
            'financed_value' => 'Valor Financiado',
            'paid_value' => 'Valor Quitado',
            'exchanged_value' => 'Valor Permutado',
        ];

        $entries = [];

        foreach ($units as $field => $label) {
            $entry = TextEntry::make("{$prefix}_{$field}")
                ->label($label)
                ->state(function (SalesBoard $record) use ($resolvePosition, $field): string {
                    $position = $resolvePosition($record);

                    if ($position === null) {
                        return '—';
                    }

                    return $field === 'reference_month'
                        ? SalesBoard::formatReferenceMonthForDisplay($position->reference_month)
                        : (string) $position->{$field};
                });

            if ($field === 'reference_month') {
                $entry->badge();
            }

            if ($field === 'total_units') {
                $entry->weight('bold');
            }

            $entries[] = $entry;
        }

        foreach ($values as $field => $label) {
            $entries[] = TextEntry::make("{$prefix}_{$field}")
                ->label($label)
                ->state(function (SalesBoard $record) use ($resolvePosition, $field): string {
                    $position = $resolvePosition($record);

                    return $position === null ? '—' : static::money($position->{$field});
                });
        }

        return $entries;
    }
}
