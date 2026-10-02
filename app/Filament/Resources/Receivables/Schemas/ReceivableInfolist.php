<?php

namespace App\Filament\Resources\Receivables\Schemas;

use App\Concerns\MoneyFormatter;
use App\DTOs\SalesBoards\EmissionSalesPosition;
use App\DTOs\SalesBoards\SalesBoardPublicationGaps;
use App\Models\Receivable;
use App\Models\SalesBoard;
use App\Models\SalesBoardPublication;
use App\Services\SalesBoards\SalesBoardPositionReader;
use App\Services\SalesBoards\SalesBoardPublicationGapClassifier;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use WeakMap;

class ReceivableInfolist
{
    /**
     * @var WeakMap<Receivable, EmissionSalesPosition>|null
     */
    private static ?WeakMap $salesPositions = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // 1. Faixa de KPIs Executivos no Topo
                ViewEntry::make('executive_summary')
                    ->label('')
                    ->view('filament.infolists.receivable-executive-summary')
                    ->columnSpanFull(),

                // 2. Identificação da Carteira
                Section::make('Identificação')
                    ->description('Operação, competência e dados cadastrais da carteira.')
                    ->extraAttributes(['class' => 'bsi-rcv-identificacao'])
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        TextEntry::make('reference_month')
                            ->label('Competência')
                            ->formatStateUsing(fn (mixed $state): string => Receivable::formatReferenceMonthForDisplay($state)),

                        TextEntry::make('emission.name')
                            ->label('Emissão')
                            ->weight('bold')
                            ->formatStateUsing(fn (Receivable $record): string => $record->emission?->name ?? '—'),

                        TextEntry::make('development_name')
                            ->label('Empreendimento')
                            ->formatStateUsing(function (Receivable $record): string {
                                $developments = $record->emission?->constructions?->pluck('development_name')->filter()->unique();

                                return ($developments && $developments->isNotEmpty())
                                    ? $developments->implode(', ')
                                    : ($record->emission?->name ?? '—');
                            }),

                        TextEntry::make('portfolio_id')
                            ->label('ID da Carteira')
                            ->placeholder('—'),

                        TextEntry::make('active_contracts_count')
                            ->label('Contratos Ativos')
                            ->numeric()
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? number_format($state, 0, ',', '.') : '—'),

                        TextEntry::make('emission.bsi_code')
                            ->label('Código BSI')
                            ->placeholder('—'),

                        TextEntry::make('guarantees_value_amount')
                            ->label('Garantias no PL do CRI')
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('created_at')
                            ->label('Data de Registro')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ]),

                // 3. Posição Financeira e Saldos
                Section::make('Posição Financeira')
                    ->description('Saldos da carteira, inadimplência e garantias consolidadas.')
                    ->extraAttributes(['class' => 'bsi-rcv-posicao-financeira'])
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        TextEntry::make('total_outstanding_balance_amount')
                            ->label('Saldo Devedor Total')
                            ->extraAttributes(['class' => 'bsi-rcv-destaque-saldo'])
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('performing_balance_post_event_amount')
                            ->label('Adimplente (Pós-Evento)')
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('non_performing_balance_post_event_amount')
                            ->label('Inadimplente (Pós-Evento)')
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('linked_credits_current_amount')
                            ->label('Créditos Vinculados em Dia')
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('performing_balance_pre_event_amount')
                            ->label('Adimplente (Pré-Evento)')
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('non_performing_balance_pre_event_amount')
                            ->label('Inadimplente (Pré-Evento)')
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('total_default_balance_amount')
                            ->label('Saldo Inadimplência Geral')
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('monthly_default_balance_amount')
                            ->label('Saldo Inadimplência Mês')
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('total_prepayment_amount')
                            ->label('Pré-Pagamento no Mês')
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),

                        TextEntry::make('guarantees_value_amount_card')
                            ->label('Garantias no PL do CRI')
                            ->state(fn (Receivable $record) => $record->guarantees_value_amount)
                            ->formatStateUsing(fn (mixed $state): string => static::formatMoney($state)),
                    ]),

                // 4. Indicadores e Risco
                Section::make('Indicadores e Métricas de Risco')
                    ->description('Índices de garantia, concentração de devedores e duration da carteira.')
                    ->extraAttributes(['class' => 'bsi-rcv-indicadores'])
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 5])
                    ->schema([
                        TextEntry::make('portfolio_ltv_ratio')
                            ->label('LTV Carteira')
                            ->formatStateUsing(fn (mixed $state): string => static::formatPercentage($state)),

                        TextEntry::make('sale_ltv_ratio')
                            ->label('LTV Venda')
                            ->formatStateUsing(fn (mixed $state): string => static::formatPercentage($state)),

                        TextEntry::make('top_five_debtors_concentration_ratio')
                            ->label('Concentração 5 Maiores')
                            ->formatStateUsing(fn (mixed $state): string => static::formatPercentage($state)),

                        TextEntry::make('portfolio_duration_years')
                            ->label('Duration (Anos)')
                            ->formatStateUsing(fn (mixed $state): string => static::formatDecimal($state, 'anos')),

                        TextEntry::make('portfolio_duration_months')
                            ->label('Duration (Meses)')
                            ->formatStateUsing(fn (mixed $state): string => static::formatDecimal($state, 'meses')),
                    ]),

                // 5. Recebíveis — Fluxo do Mês (Matriz Juros x Amortização)
                Section::make('Recebíveis — Fluxo do Mês')
                    ->description('Valores esperados, recebidos ordinários, antecipações e recuperação de inadimplência.')
                    ->columnSpanFull()
                    ->schema([
                        ViewEntry::make('cashflow_matrix')
                            ->label('')
                            ->view('filament.infolists.receivable-cashflow-matrix'),
                    ]),

                // 6. Projeções Futuras e Faixas de Vencimento (Aging analítico)
                Section::make('Projeções Futuras e Faixas de Vencimento')
                    ->description('Distribuição da carteira vinculada (fluxo futuro), atrasos e quitações antecipadas.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        ViewEntry::make('aging_projections')
                            ->label('')
                            ->view('filament.infolists.receivable-aging-projections-table'),
                    ]),

                // 7. Vendas e Estoque (exibido quando a Emissão tem posição do Quadro de Vendas na competência)
                Section::make('Vendas e Estoque')
                    ->description('Posição consolidada da Emissão: soma dos empreendimentos, cada um com o quadro da competência ou a última posição conhecida.')
                    ->columnSpanFull()
                    ->visible(fn (Receivable $record): bool => static::salesPosition($record)?->hasData() ?? false)
                    ->schema([
                        ViewEntry::make('sales_stock')
                            ->label('')
                            ->view('filament.infolists.receivable-sales-stock')
                            ->viewData(fn (Receivable $record): array => static::salesStockViewData($record)),
                    ]),

                // 8. Observações e Detalhes da Carteira
                Section::make('Observações e Taxas da Carteira')
                    ->description('Detalhamento da taxa média contratual e informações adicionais.')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('average_rate_details')
                            ->label('Taxa Média da Carteira')
                            ->placeholder('Nenhuma observação informada.')
                            ->extraAttributes(['class' => 'bsi-rcv-obs-block'])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Posição do Quadro de Vendas da Emissão na competência do Recebível.
     *
     * Lida pelo {@see SalesBoardPositionReader}, a mesma regra do relatório
     * mensal e das garantias: soma por empreendimento, com a última posição
     * conhecida de quem não atualizou o quadro. A tela pegava um único quadro
     * da competência exata com `first()`; numa Emissão com mais de um
     * empreendimento mostrava só um deles como se fosse o total, e o mês sem
     * quadro exato escondia a seção mesmo com posição conhecida.
     *
     * A seção pergunta duas vezes (visibilidade e conteúdo). O mapa fraco
     * guarda a leitura por instância do registro, sem prendê-lo na memória.
     */
    public static function salesPosition(Receivable $record): ?EmissionSalesPosition
    {
        if (($record->emission === null) || ($record->reference_month === null)) {
            return null;
        }

        self::$salesPositions ??= new WeakMap;

        return self::$salesPositions[$record] ??= app(SalesBoardPositionReader::class)
            ->forEmission($record->emission, $record->reference_month);
    }

    /**
     * O empreendimento sem o quadro publicado da competência vem explicado
     * pelo ciclo mensal ({@see SalesBoardPublicationGapClassifier}): aguardando
     * publicação, ou cancelada pela Gestão -- esta com data e motivo, porque a
     * tela é interna.
     *
     * @return array{position: EmissionSalesPosition|null, automatedSalesBoardIds: list<int>, publicationGaps: SalesBoardPublicationGaps}
     */
    protected static function salesStockViewData(Receivable $record): array
    {
        $position = static::salesPosition($record);
        $salesBoardIds = $position?->salesBoards()
            ->map(fn (SalesBoard $salesBoard): int => (int) $salesBoard->getKey())
            ->all() ?? [];

        return [
            'position' => $position,
            'automatedSalesBoardIds' => $salesBoardIds === []
                ? []
                /**
                 * Distintos: a retificação aprovada encadeia outra publicação
                 * ao mesmo quadro, e o quadro continua sendo um só.
                 */
                : SalesBoardPublication::query()
                    ->whereIn('sales_board_id', $salesBoardIds)
                    ->distinct()
                    ->pluck('sales_board_id')
                    ->map(fn (mixed $salesBoardId): int => (int) $salesBoardId)
                    ->values()
                    ->all(),
            'publicationGaps' => (($position !== null) && ($record->emission !== null))
                ? app(SalesBoardPublicationGapClassifier::class)->classify($record->emission, $position)
                : SalesBoardPublicationGaps::none(),
        ];
    }

    protected static function formatMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value);
    }

    protected static function formatPercentage(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format(((float) $value) * 100, 2, ',', '.').'%';
    }

    protected static function formatDecimal(mixed $value, string $unit = ''): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $formatted = number_format((float) $value, 2, ',', '.');

        return filled($unit) ? "{$formatted} {$unit}" : $formatted;
    }
}
