<?php

namespace App\Filament\Resources\SalesBoards\Schemas;

use App\Concerns\MoneyFormatter;
use App\Enums\SalesBoardSource;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardPublication;
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
            static::governancePublicationSection(),
            static::operationDataSection(),
            static::initialPositionSection(),
            static::currentPositionSection(),
        ]);
    }

    /**
     * Um quadro publicado pela governança do ciclo mensal é imutável, e a tela
     * diz isso antes de o operador tentar alterá-lo -- não depois, pela recusa do
     * guard.
     */
    protected static function governancePublicationSection(): Section
    {
        return Section::make('Publicado pelo fluxo de governança')
            ->icon('heroicon-o-lock-closed')
            ->columnSpanFull()
            ->visible(fn (SalesBoard $record): bool => static::publication($record) !== null)
            ->schema([
                TextEntry::make('governance_immutability')
                    ->hiddenLabel()
                    ->state('Este quadro foi publicado pelo fluxo de governança e não pode mais ser alterado ou excluído manualmente.')
                    ->icon('heroicon-m-lock-closed')
                    ->weight('bold')
                    ->helperText('Corrigir a posição publicada não é editar o quadro: a correção acontece na fonte e passa de novo pela validação da construtora e pela análise da Gestão.'),

                TextEntry::make('governance_publication')
                    ->label('Publicação')
                    ->state(function (SalesBoard $record): string {
                        $publication = static::publication($record);

                        return sprintf(
                            'Publicado em %s por %s, a partir da versão %s do ciclo.',
                            $publication?->published_at?->format('d/m/Y \à\s H:i') ?? '—',
                            $publication?->publishedBy?->name ?? '—',
                            $publication?->baseline?->versionLabel() ?? '—',
                        );
                    })
                    ->url(fn (SalesBoard $record): ?string => ($cycleId = static::publication($record)?->sales_board_cycle_id) === null
                        ? null
                        : SalesBoardCycleResource::getUrl('view', ['record' => $cycleId])),
            ]);
    }

    protected static function publication(SalesBoard $record): ?SalesBoardPublication
    {
        return SalesBoardPublication::query()
            ->with(['publishedBy', 'baseline'])
            ->where('sales_board_id', $record->getKey())
            ->first();
    }

    protected static function operationDataSection(): Section
    {
        return Section::make('Dados da Operação')
            ->description('Contexto e identificação da operação securitizada.')
            ->icon('heroicon-o-building-office')
            ->columnSpanFull()
            ->columns(['default' => 1, 'sm' => 2, 'md' => 4])
            ->schema([
                TextEntry::make('emission.name')
                    ->label('Operação')
                    ->weight('bold')
                    ->icon('heroicon-m-briefcase'),
                TextEntry::make('construction.development_name')
                    ->label('Empreendimento')
                    ->weight('bold')
                    ->icon('heroicon-m-building-office-2'),
                TextEntry::make('emission.sales_board_source')
                    ->label('Modo do Quadro de Vendas')
                    ->formatStateUsing(fn (?SalesBoardSource $state): string => $state?->label() ?? '—')
                    ->icon('heroicon-m-cog-6-tooth')
                    ->helperText(fn (SalesBoard $record): ?string => $record->emission?->usesAutomatedSalesBoard()
                        ? 'Competências a partir de '.($record->emission->sales_board_automation_start_reference_month?->format('m/Y') ?? '—').' são produzidas pelo ciclo mensal e publicadas pela Gestão.'
                        : 'A posição mensal é registrada manualmente.'),
                TextEntry::make('emission.status')
                    ->label('Status da Operação')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Emission::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'warning',
                        'active' => 'success',
                        'default' => 'danger',
                        'closed' => 'danger',
                        default => 'gray',
                    }),
            ]);
    }

    protected static function initialPositionSection(): Section
    {
        return Section::make('Início da Operação')
            ->description('Posição inicial da operação, consolidada quando a emissão deixou o status "Em Elaboração". Imutável.')
            ->icon('heroicon-o-lock-closed')
            ->columnSpanFull()
            ->schema([
                TextEntry::make('initial_position_pending')
                    ->label('Status de Consolidação')
                    ->state(fn (SalesBoard $record): string => $record->hasInitialPosition()
                        ? ($record->initialPosition?->created_at?->format('d/m/Y \à\s H:i') ?? '—')
                        : 'Será consolidada quando a emissão deixar "Em Elaboração".')
                    ->color(fn (SalesBoard $record): string => $record->hasInitialPosition() ? 'success' : 'warning')
                    ->badge(fn (SalesBoard $record): bool => ! $record->hasInitialPosition())
                    ->icon(fn (SalesBoard $record): string => $record->hasInitialPosition() ? 'heroicon-m-check-circle' : 'heroicon-m-clock')
                    ->helperText(fn (SalesBoard $record): ?string => $record->hasInitialPosition() ? null : 'Aguardando consolidação')
                    ->visible(fn (SalesBoard $record): bool => ! $record->hasInitialPosition())
                    ->columnSpanFull(),

                Grid::make(['default' => 1, 'sm' => 2, 'md' => 4])
                    ->visible(fn (SalesBoard $record): bool => $record->hasInitialPosition())
                    ->schema([
                        TextEntry::make('initial_reference_month')
                            ->label('Competência')
                            ->state(fn (SalesBoard $record): string => SalesBoard::formatReferenceMonthForDisplay($record->initialPosition?->reference_month))
                            ->badge()
                            ->icon('heroicon-m-calendar')
                            ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2]),

                        TextEntry::make('initial_consolidated_at')
                            ->label('Consolidado em')
                            ->state(fn (SalesBoard $record): string => $record->initialPosition?->created_at?->format('d/m/Y \à\s H:i') ?? '—')
                            ->icon('heroicon-m-clock')
                            ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2]),

                        TextEntry::make('initial_stock_units')
                            ->label('Estoque')
                            ->state(fn (SalesBoard $record): string => (string) ($record->initialPosition?->stock_units ?? 0))
                            ->suffix(' un.')
                            ->weight('semibold'),
                        TextEntry::make('initial_financed_units')
                            ->label('Financiado')
                            ->state(fn (SalesBoard $record): string => (string) ($record->initialPosition?->financed_units ?? 0))
                            ->suffix(' un.')
                            ->weight('semibold'),
                        TextEntry::make('initial_paid_units')
                            ->label('Quitado')
                            ->state(fn (SalesBoard $record): string => (string) ($record->initialPosition?->paid_units ?? 0))
                            ->suffix(' un.')
                            ->weight('semibold'),
                        TextEntry::make('initial_exchanged_units')
                            ->label('Permutado')
                            ->state(fn (SalesBoard $record): string => (string) ($record->initialPosition?->exchanged_units ?? 0))
                            ->suffix(' un.')
                            ->weight('semibold'),

                        TextEntry::make('initial_stock_value')
                            ->label('Valor em Estoque')
                            ->state(fn (SalesBoard $record): string => static::money($record->initialPosition?->stock_value))
                            ->weight('semibold'),
                        TextEntry::make('initial_financed_value')
                            ->label('Valor Financiado')
                            ->state(fn (SalesBoard $record): string => static::money($record->initialPosition?->financed_value))
                            ->weight('semibold'),
                        TextEntry::make('initial_paid_value')
                            ->label('Valor Quitado')
                            ->state(fn (SalesBoard $record): string => static::money($record->initialPosition?->paid_value))
                            ->weight('semibold'),
                        TextEntry::make('initial_exchanged_value')
                            ->label('Valor Permutado')
                            ->state(fn (SalesBoard $record): string => static::money($record->initialPosition?->exchanged_value))
                            ->weight('semibold'),

                        TextEntry::make('initial_total_units')
                            ->label('Quantidade Total')
                            ->state(fn (SalesBoard $record): string => "{$record->initialPosition?->total_units} unidades")
                            ->weight('bold')
                            ->badge()
                            ->color('warning')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected static function currentPositionSection(): Section
    {
        return Section::make('Posição Atual')
            ->description('Última posição registrada para este empreendimento.')
            ->icon('heroicon-o-chart-bar')
            ->columnSpanFull()
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2, 'md' => 4])
                    ->schema([
                        TextEntry::make('reference_month')
                            ->label('Competência')
                            ->state(fn (SalesBoard $record): string => SalesBoard::formatReferenceMonthForDisplay($record->reference_month))
                            ->badge()
                            ->icon('heroicon-m-calendar')
                            ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2]),

                        TextEntry::make('current_position_updated_at')
                            ->label('Atualizado em')
                            ->state(fn (SalesBoard $record): string => $record->updated_at?->format('d/m/Y \à\s H:i') ?? '—')
                            ->icon('heroicon-m-clock')
                            ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2]),

                        TextEntry::make('stock_units')
                            ->label('Estoque')
                            ->state(fn (SalesBoard $record): string => (string) $record->stock_units)
                            ->suffix(' un.')
                            ->weight('semibold'),
                        TextEntry::make('financed_units')
                            ->label('Financiado')
                            ->state(fn (SalesBoard $record): string => (string) $record->financed_units)
                            ->suffix(' un.')
                            ->weight('semibold'),
                        TextEntry::make('paid_units')
                            ->label('Quitado')
                            ->state(fn (SalesBoard $record): string => (string) $record->paid_units)
                            ->suffix(' un.')
                            ->weight('semibold'),
                        TextEntry::make('exchanged_units')
                            ->label('Permutado')
                            ->state(fn (SalesBoard $record): string => (string) $record->exchanged_units)
                            ->suffix(' un.')
                            ->weight('semibold'),

                        TextEntry::make('stock_value')
                            ->label('Valor em Estoque')
                            ->state(fn (SalesBoard $record): string => static::money($record->stock_value))
                            ->weight('semibold'),
                        TextEntry::make('financed_value')
                            ->label('Valor Financiado')
                            ->state(fn (SalesBoard $record): string => static::money($record->financed_value))
                            ->weight('semibold'),
                        TextEntry::make('paid_value')
                            ->label('Valor Quitado')
                            ->state(fn (SalesBoard $record): string => static::money($record->paid_value))
                            ->weight('semibold'),
                        TextEntry::make('exchanged_value')
                            ->label('Valor Permutado')
                            ->state(fn (SalesBoard $record): string => static::money($record->exchanged_value))
                            ->weight('semibold'),

                        TextEntry::make('total_units')
                            ->label('Quantidade Total')
                            ->state(fn (SalesBoard $record): string => "{$record->total_units} unidades")
                            ->weight('bold')
                            ->badge()
                            ->color('warning')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected static function money(mixed $value): string
    {
        return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value);
    }
}
