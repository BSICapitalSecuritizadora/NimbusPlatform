<?php

namespace App\Filament\Resources\SalesBoardCycles\Schemas;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardCycleNextAction;
use DateTimeInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
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
            static::nextActionSection(),
            static::identificationSection(),
            static::rectificationSection(),
            static::positionSection(),
            static::bridgeSection(),
            static::warningsSection(),
            static::versionSection(),
        ]);
    }

    /**
     * Onde a competência está e o que vem depois, antes de qualquer número.
     *
     * Derivado da situação do ciclo e do impacto de fonte registrado na última
     * verificação -- nada é apurado ao abrir a tela.
     */
    protected static function nextActionSection(): Section
    {
        return Section::make('Próxima ação')
            ->extraAttributes(['class' => 'bsi-cycle-section bsi-cycle-next-action'])
            ->icon('heroicon-o-arrow-right-circle')
            ->columnSpanFull()
            ->schema([
                TextEntry::make('next_action_headline')
                    ->label('Orientação do ciclo')
                    ->hiddenLabel()
                    ->state(fn (SalesBoardCycle $record): string => SalesBoardCycleNextAction::for($record)->headline)
                    ->icon(fn (SalesBoardCycle $record): string => SalesBoardCycleNextAction::for($record)->icon)
                    ->color(fn (SalesBoardCycle $record): string => SalesBoardCycleNextAction::for($record)->color)
                    ->weight('bold')
                    ->helperText(fn (SalesBoardCycle $record): string => SalesBoardCycleNextAction::for($record)->detail),

                TextEntry::make('next_action_source_checked_at')
                    ->label('Verificação da fonte')
                    ->hiddenLabel()
                    ->state(fn (SalesBoardCycle $record): string => $record->currentBaseline?->last_checked_at === null
                        ? 'A fonte ainda não foi verificada desde a apuração. Use “Verificar alterações” para conferir agora.'
                        : sprintf(
                            'Situação da fonte na última verificação, em %s. Use “Verificar alterações” para conferir agora.',
                            static::businessDateTime($record->currentBaseline->last_checked_at),
                        ))
                    ->color('gray')
                    ->size('sm')
                    ->visible(fn (SalesBoardCycle $record): bool => $record->current_baseline_id !== null
                        && ! in_array($record->status, [SalesBoardCycleStatus::Approved, SalesBoardCycleStatus::Cancelled], true)),
            ]);
    }

    protected static function identificationSection(): Section
    {
        return Section::make('Competência')
            ->extraAttributes(['class' => 'bsi-cycle-section bsi-cycle-identification'])
            ->description('A que empreendimento e a que mês esta posição pertence.')
            ->icon('heroicon-o-calendar-days')
            ->columnSpanFull()
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
            ->schema([
                TextEntry::make('emission.name')
                    ->label('Operação')
                    ->weight('bold')
                    ->icon('heroicon-m-briefcase'),

                TextEntry::make('construction.development_name')
                    ->label('Empreendimento')
                    ->weight('bold')
                    ->icon('heroicon-m-building-office-2'),

                TextEntry::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->badge()
                    ->color('gray')
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
                    ->placeholder(fn (SalesBoardCycle $record): string => static::withoutUserLabel($record))
                    ->icon('heroicon-m-user'),

                /**
                 * O cancelamento continua à vista depois da reabertura: é o
                 * registro do último cancelamento, ao lado de quem reabriu e
                 * por quê. As horas saem no fuso de negócio, como no resto da
                 * tela -- não no UTC em que são gravadas.
                 */
                TextEntry::make('cancelledBy.name')
                    ->label(fn (SalesBoardCycle $record): string => $record->reopened_at === null ? 'Cancelada por' : 'Último cancelamento')
                    ->placeholder('Sem usuário registrado')
                    ->icon('heroicon-m-no-symbol')
                    ->helperText(fn (SalesBoardCycle $record): ?string => static::businessDateTime($record->cancelled_at))
                    ->visible(fn (SalesBoardCycle $record): bool => static::showsCancellation($record)),

                TextEntry::make('cancellation_reason')
                    ->label(fn (SalesBoardCycle $record): string => $record->reopened_at === null ? 'Motivo do cancelamento' : 'Motivo do último cancelamento')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn (SalesBoardCycle $record): bool => static::showsCancellation($record)),

                TextEntry::make('reopenedBy.name')
                    ->label('Reaberta por')
                    ->placeholder('Sem usuário registrado')
                    ->icon('heroicon-m-arrow-uturn-up')
                    ->helperText(fn (SalesBoardCycle $record): ?string => static::businessDateTime($record->reopened_at))
                    ->visible(fn (SalesBoardCycle $record): bool => $record->reopened_at !== null),

                TextEntry::make('reopen_reason')
                    ->label('Motivo da reabertura')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn (SalesBoardCycle $record): bool => $record->reopened_at !== null),
            ]);
    }

    /**
     * As retificações da competência publicada: a em andamento e o histórico.
     */
    protected static function rectificationSection(): Section
    {
        return Section::make('Retificação')
            ->extraAttributes(['class' => 'bsi-cycle-section bsi-cycle-rectification'])
            ->description('A correção da posição publicada desta competência. Enquanto a retificação corre, a posição publicada continua valendo.')
            ->icon('heroicon-o-pencil-square')
            ->columnSpanFull()
            ->visible(fn (SalesBoardCycle $record): bool => $record->hasPublication() && $record->rectifications()->exists())
            ->schema([
                ViewEntry::make('rectifications')
                    ->hiddenLabel()
                    ->view('filament.infolists.sales-board-cycle-rectifications'),
            ]);
    }

    /**
     * A ponte com a competência anterior: como a posição congelada dela chega à
     * desta versão, pelos movimentos congelados.
     */
    protected static function bridgeSection(): Section
    {
        return Section::make('Ponte com a competência anterior')
            ->extraAttributes(['class' => 'bsi-cycle-section bsi-cycle-bridge'])
            ->description('Por balde: o que veio da competência anterior, o que entrou e saiu com explicação e o que mudou sem movimento que explique.')
            ->icon('heroicon-o-arrows-right-left')
            ->columnSpanFull()
            ->collapsible()
            ->visible(fn (SalesBoardCycle $record): bool => $record->current_baseline_id !== null)
            ->schema([
                ViewEntry::make('competence_bridge')
                    ->hiddenLabel()
                    ->view('filament.infolists.sales-board-cycle-competence-bridge'),
            ]);
    }

    protected static function showsCancellation(SalesBoardCycle $record): bool
    {
        return ($record->status === SalesBoardCycleStatus::Cancelled) || ($record->reopened_at !== null);
    }

    protected static function businessDateTime(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : BusinessTime::at($instant)->format('d/m/Y \à\s H:i');
    }

    protected static function positionSection(): Section
    {
        return Section::make('Posição congelada')
            ->extraAttributes(['class' => 'bsi-cycle-section bsi-cycle-position'])
            ->description('Os totais são a soma das unidades da versão vigente, e não um número digitado à parte.')
            ->icon('heroicon-o-lock-closed')
            ->columnSpanFull()
            ->schema([
                Grid::make(['default' => 2, 'sm' => 3, 'lg' => 5])
                    ->extraAttributes(['class' => 'bsi-cycle-kpis'])
                    ->schema([
                        static::bucket('Estoque', 'stock'),
                        static::bucket('Financiado', 'financed'),
                        static::bucket('Quitado', 'settled'),
                        static::bucket('Permutado', 'exchanged'),

                        TextEntry::make('total_units')
                            ->label('Total')
                            ->state(fn (SalesBoardCycle $record): string => static::units($record->currentBaseline?->units_total))
                            ->weight('bold')
                            ->size('lg'),
                    ]),

                TextEntry::make('consolidated_value')
                    ->label('Valor consolidado')
                    ->state(fn (SalesBoardCycle $record): string => static::money(
                        $record->currentBaseline?->totalValueCents(),
                    ))
                    ->weight('bold')
                    ->size('lg')
                    ->extraAttributes(['class' => 'bsi-cycle-consolidated-value'])
                    ->columnSpanFull(),

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

    /**
     * Os avisos que a apuração registrou na versão vigente: o que o Nimbus
     * sinalizou sem impedir o congelamento. Congelados com a versão -- vários
     * dependem do status de hoje e não se reconstroem depois --, e por isso a
     * tela os lê da versão, e não da fonte viva.
     */
    protected static function warningsSection(): Section
    {
        return Section::make('Avisos da apuração')
            ->extraAttributes(['class' => 'bsi-cycle-section bsi-cycle-warnings'])
            ->description('Sinais que a apuração registrou nesta versão sem impedir o congelamento: confira antes de enviar à construtora e de aprovar.')
            ->icon('heroicon-o-exclamation-triangle')
            ->columnSpanFull()
            ->visible(fn (SalesBoardCycle $record): bool => $record->current_baseline_id !== null)
            ->schema([
                ViewEntry::make('derivation_warnings')
                    ->hiddenLabel()
                    ->view('filament.infolists.sales-board-cycle-warnings'),
            ]);
    }

    protected static function versionSection(): Section
    {
        return Section::make('Versão vigente e a fonte')
            ->extraAttributes(['class' => 'bsi-cycle-section bsi-cycle-metadata'])
            ->description('O resumo da fonte material observada e o da posição derivada dela são perguntas diferentes: a fonte pode mudar sem que nenhum número mude.')
            ->icon('heroicon-o-finger-print')
            ->columnSpanFull()
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->schema([
                TextEntry::make('currentBaseline.version')
                    ->label('Versão')
                    ->state(fn (SalesBoardCycle $record): string => $record->currentBaseline?->versionLabel() ?? '—')
                    ->badge()
                    ->color('gray'),

                TextEntry::make('currentBaseline.computed_at')
                    ->label('Apurada em')
                    ->dateTime('d/m/Y \à\s H:i', BusinessTime::timezone())
                    ->placeholder('—'),

                TextEntry::make('currentBaseline.computedBy.name')
                    ->label('Apurada por')
                    ->placeholder(fn (SalesBoardCycle $record): string => ((int) $record->currentBaseline?->version === 1)
                        ? static::withoutUserLabel($record)
                        : 'Sem usuário registrado'),

                TextEntry::make('currentBaseline.reason')
                    ->label('Motivo do recálculo')
                    ->placeholder('Versão inicial')
                    ->columnSpan(1),

                TextEntry::make('stale_impact')
                    ->label('Situação da fonte')
                    ->state(fn (SalesBoardCycle $record): string => static::publishedSourceLabel($record)
                        ?? ($record->currentBaseline?->stale_impact ?? SalesBoardStaleImpact::None)->label())
                    ->badge()
                    ->color(fn (SalesBoardCycle $record): string => static::publishedSourceLabel($record) !== null
                        ? 'info'
                        : ($record->currentBaseline?->stale_impact ?? SalesBoardStaleImpact::None)->color())
                    ->helperText(fn (SalesBoardCycle $record): string => static::publishedSourceLabel($record) !== null
                        ? 'A posição publicada não é recalculada: os fatos alterados depois da publicação entram como movimentos extemporâneos na próxima competência. A última competência publicada pode ser corrigida pela Gestão com “Retificar competência”.'
                        : ($record->currentBaseline?->stale_impact ?? SalesBoardStaleImpact::None)->description())
                    ->columnSpan(['default' => 1, 'lg' => 2]),

                TextEntry::make('currentBaseline.last_checked_at')
                    ->label('Última verificação')
                    ->dateTime('d/m/Y \à\s H:i', BusinessTime::timezone())
                    ->placeholder('—'),

                TextEntry::make('currentBaseline.stale_detected_at')
                    ->label('Primeira divergência')
                    ->dateTime('d/m/Y \à\s H:i', BusinessTime::timezone())
                    ->placeholder('Nunca divergiu')
                    ->helperText('Fica registrado mesmo que a fonte volte ao que era.'),

                TextEntry::make('currentBaseline.source_fingerprint')
                    ->label('Resumo da fonte')
                    ->state(fn (SalesBoardCycle $record): string => static::fingerprint($record->currentBaseline?->source_fingerprint))
                    ->copyable()
                    ->copyableState(fn (SalesBoardCycle $record): ?string => $record->currentBaseline?->source_fingerprint)
                    ->extraAttributes(['class' => 'font-mono bsi-cycle-fingerprint'])
                    ->columnStart(1)
                    ->columnSpan(['default' => 1, 'lg' => 2]),

                TextEntry::make('currentBaseline.snapshot_fingerprint')
                    ->label('Resumo da posição')
                    ->state(fn (SalesBoardCycle $record): string => static::fingerprint($record->currentBaseline?->snapshot_fingerprint))
                    ->copyable()
                    ->copyableState(fn (SalesBoardCycle $record): ?string => $record->currentBaseline?->snapshot_fingerprint)
                    ->extraAttributes(['class' => 'font-mono bsi-cycle-fingerprint'])
                    ->columnSpan(['default' => 1, 'lg' => 2]),
            ]);
    }

    /**
     * O rótulo próprio da competência publicada, sem retificação aberta, cuja
     * fonte mudou depois da publicação -- `null` nos outros casos. "Alterações
     * materiais" ali mandaria recalcular o que não se recalcula.
     */
    public static function publishedSourceLabel(SalesBoardCycle $record): ?string
    {
        $impact = $record->currentBaseline?->stale_impact ?? SalesBoardStaleImpact::None;

        if (($record->status !== SalesBoardCycleStatus::Approved) || ($impact === SalesBoardStaleImpact::None)) {
            return null;
        }

        return 'Fatos posteriores à publicação';
    }

    /**
     * Quem congelou, quando não houve usuário.
     *
     * A automação gera o ciclo sem ator, e o traço sozinho parecia dado faltando.
     * O vínculo com o alvo da automação é o que prova a origem; sem ele, o ciclo
     * veio de outro processo sem usuário (o comando, por exemplo), e a tela não
     * atribui à automação o que ela não fez.
     */
    protected static function withoutUserLabel(SalesBoardCycle $record): string
    {
        return SalesBoardAutomationTarget::query()->where('sales_board_cycle_id', $record->getKey())->exists()
            ? 'Automação do Quadro'
            : 'Sem usuário registrado';
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
