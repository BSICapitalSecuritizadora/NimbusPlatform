<?php

namespace App\Filament\Resources\SalesBoardCycles\Schemas;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardCycle;
use App\Support\Money\IntegerMoney;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A leitura de um ciclo: o que foi apurado, em que versão, e como essa versão se
 * relaciona com a fonte de hoje.
 *
 * Tudo vem da versão congelada, nunca da fonte viva. É o que faz a tela
 * continuar mostrando a mesma coisa depois que um contrato muda -- e a diferença
 * aparecer como alteração detectada, em vez de o passado se reescrever sozinho.
 */
class SalesBoardCycleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            static::identificationSection(),
            static::positionSection(),
            static::versionSection(),
        ]);
    }

    protected static function identificationSection(): Section
    {
        return Section::make('Competência')
            ->description('A que empreendimento e a que mês esta posição pertence.')
            ->icon('heroicon-o-calendar-days')
            ->columnSpanFull()
            ->columns(['default' => 1, 'sm' => 2, 'md' => 4])
            ->schema([
                TextEntry::make('emission.name')
                    ->label('Operação')
                    ->weight('bold')
                    ->icon('heroicon-m-briefcase')
                    ->columnSpan(['default' => 1, 'md' => 2]),

                TextEntry::make('construction.development_name')
                    ->label('Empreendimento')
                    ->weight('bold')
                    ->icon('heroicon-m-building-office-2')
                    ->columnSpan(['default' => 1, 'md' => 2]),

                TextEntry::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->badge()
                    ->icon('heroicon-m-calendar'),

                TextEntry::make('position_date')
                    ->label('Posição em')
                    ->date('d/m/Y')
                    ->icon('heroicon-m-clock')
                    ->helperText('A posição é sempre a do último dia da competência.'),

                TextEntry::make('status')
                    ->label('Situação do ciclo')
                    ->badge()
                    ->formatStateUsing(fn (SalesBoardCycleStatus $state): string => $state->label())
                    ->color(fn (SalesBoardCycleStatus $state): string => $state->color()),

                TextEntry::make('createdBy.name')
                    ->label('Congelado por')
                    ->placeholder('—')
                    ->icon('heroicon-m-user'),
            ]);
    }

    protected static function positionSection(): Section
    {
        return Section::make('Posição congelada')
            ->description('Os totais são a soma das unidades da versão vigente, e não um número digitado à parte.')
            ->icon('heroicon-o-lock-closed')
            ->columnSpanFull()
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2, 'md' => 5])->schema([
                    static::bucket('Estoque', 'stock'),
                    static::bucket('Financiado', 'financed'),
                    static::bucket('Quitado', 'settled'),
                    static::bucket('Permutado', 'exchanged'),

                    TextEntry::make('total_units')
                        ->label('Total')
                        ->state(fn (SalesBoardCycle $record): string => static::units($record->currentBaseline?->units_total))
                        ->helperText(fn (SalesBoardCycle $record): string => static::money(
                            $record->currentBaseline?->totalValueCents(),
                        ))
                        ->weight('bold')
                        ->size('lg'),
                ]),

                TextEntry::make('undetermined_units')
                    ->label('Indeterminadas')
                    ->state(fn (SalesBoardCycle $record): string => static::units($record->currentBaseline?->undetermined_units))
                    ->badge()
                    ->color('danger')
                    ->helperText('Unidades que a apuração não conseguiu classificar. Uma competência só é congelada com este número em zero.')
                    ->visible(fn (SalesBoardCycle $record): bool => ((int) $record->currentBaseline?->undetermined_units) > 0)
                    ->columnSpanFull(),
            ]);
    }

    protected static function versionSection(): Section
    {
        return Section::make('Versão vigente e a fonte')
            ->description('O resumo da fonte material observada e o da posição derivada dela são perguntas diferentes: a fonte pode mudar sem que nenhum número mude.')
            ->icon('heroicon-o-finger-print')
            ->columnSpanFull()
            ->columns(['default' => 1, 'sm' => 2, 'md' => 4])
            ->schema([
                TextEntry::make('currentBaseline.version')
                    ->label('Versão')
                    ->state(fn (SalesBoardCycle $record): string => $record->currentBaseline?->versionLabel() ?? '—')
                    ->badge()
                    ->color('gray'),

                TextEntry::make('currentBaseline.computed_at')
                    ->label('Apurada em')
                    ->dateTime('d/m/Y \à\s H:i')
                    ->placeholder('—'),

                TextEntry::make('currentBaseline.computedBy.name')
                    ->label('Apurada por')
                    ->placeholder('—'),

                TextEntry::make('currentBaseline.reason')
                    ->label('Motivo do recálculo')
                    ->placeholder('Versão inicial')
                    ->columnSpan(['default' => 1, 'md' => 1]),

                TextEntry::make('stale_impact')
                    ->label('Situação da fonte')
                    ->state(fn (SalesBoardCycle $record): string => ($record->currentBaseline?->stale_impact ?? SalesBoardStaleImpact::None)->label())
                    ->badge()
                    ->color(fn (SalesBoardCycle $record): string => ($record->currentBaseline?->stale_impact ?? SalesBoardStaleImpact::None)->color())
                    ->helperText(fn (SalesBoardCycle $record): string => ($record->currentBaseline?->stale_impact ?? SalesBoardStaleImpact::None)->description())
                    ->columnSpan(['default' => 1, 'md' => 2]),

                TextEntry::make('currentBaseline.last_checked_at')
                    ->label('Última verificação')
                    ->dateTime('d/m/Y \à\s H:i')
                    ->placeholder('—'),

                TextEntry::make('currentBaseline.stale_detected_at')
                    ->label('Primeira divergência')
                    ->dateTime('d/m/Y \à\s H:i')
                    ->placeholder('Nunca divergiu')
                    ->helperText('Fica registrado mesmo que a fonte volte ao que era.'),

                TextEntry::make('currentBaseline.source_fingerprint')
                    ->label('Resumo da fonte')
                    ->state(fn (SalesBoardCycle $record): string => static::fingerprint($record->currentBaseline?->source_fingerprint))
                    ->copyable()
                    ->copyableState(fn (SalesBoardCycle $record): ?string => $record->currentBaseline?->source_fingerprint)
                    ->extraAttributes(['class' => 'font-mono'])
                    ->columnSpan(['default' => 1, 'md' => 2]),

                TextEntry::make('currentBaseline.snapshot_fingerprint')
                    ->label('Resumo da posição')
                    ->state(fn (SalesBoardCycle $record): string => static::fingerprint($record->currentBaseline?->snapshot_fingerprint))
                    ->copyable()
                    ->copyableState(fn (SalesBoardCycle $record): ?string => $record->currentBaseline?->snapshot_fingerprint)
                    ->extraAttributes(['class' => 'font-mono'])
                    ->columnSpan(['default' => 1, 'md' => 2]),
            ]);
    }

    protected static function bucket(string $label, string $prefix): TextEntry
    {
        return TextEntry::make($prefix.'_bucket')
            ->label($label)
            ->state(fn (SalesBoardCycle $record): string => static::units($record->currentBaseline?->{$prefix.'_units'}))
            ->helperText(fn (SalesBoardCycle $record): string => static::money(
                IntegerMoney::cents($record->currentBaseline?->{$prefix.'_value'}),
            ))
            ->weight('semibold')
            ->size('lg');
    }

    protected static function units(?int $units): string
    {
        return $units === null ? '—' : $units.' un.';
    }

    /**
     * `null` vira "indisponível", nunca `R$ 0,00`: valor desconhecido e valor
     * zero são fatos diferentes, e a tela não pode confundi-los.
     */
    protected static function money(?int $cents): string
    {
        return $cents === null ? 'indisponível' : 'R$ '.IntegerMoney::format($cents);
    }

    protected static function fingerprint(?string $fingerprint): string
    {
        if ($fingerprint === null) {
            return '—';
        }

        return substr($fingerprint, 0, 12).'…';
    }
}
