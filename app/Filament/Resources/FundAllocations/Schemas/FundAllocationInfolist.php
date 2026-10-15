<?php

namespace App\Filament\Resources\FundAllocations\Schemas;

use App\Models\BankApplicationBalanceSnapshot;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Support\Funds\FundAllocationMoney;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FundAllocationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contexto')
                ->columns(3)
                ->schema([
                    TextEntry::make('competence_label')
                        ->label('Competência')
                        ->state(fn (FundAllocationPeriod $record): string => $record->competence?->format('m/Y') ?? '—')
                        ->weight('semibold'),

                    TextEntry::make('bank_account_label')
                        ->label('Conta bancária')
                        ->state(fn (FundAllocationPeriod $record): string => $record->bankApplication?->bankAccount?->optionLabel() ?? '—')
                        ->columnSpan(2),

                    TextEntry::make('bank_application_label')
                        ->label('Aplicação bancária')
                        ->state(fn (FundAllocationPeriod $record): string => $record->bankApplication?->optionLabel() ?? '—')
                        ->fontFamily('mono')
                        ->columnSpan(2),

                    TextEntry::make('shared_badge')
                        ->label('Uso')
                        ->state(fn (FundAllocationPeriod $record): string => ($record->bankApplication !== null && $record->bankApplication->isShared()) ? 'Compartilhado' : 'Uso exclusivo')
                        ->badge()
                        ->color(fn (FundAllocationPeriod $record): string => ($record->bankApplication !== null && $record->bankApplication->isShared()) ? 'warning' : 'gray'),

                    TextEntry::make('shared_note')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->state(fn (FundAllocationPeriod $record): string => ($record->bankApplication !== null && $record->bankApplication->isShared())
                            ? 'Esta aplicação física atende a fundos de mais de uma operação. A distribuição abaixo reparte o saldo de referência entre todos eles.'
                            : 'Esta aplicação atende a uma única operação até o momento.')
                        ->color('gray'),

                    TextEntry::make('shared_emissions')
                        ->label('Operações atendidas')
                        ->columnSpanFull()
                        ->state(fn (FundAllocationPeriod $record): string => $record->bankApplication?->sharedEmissions()->pluck('name')->implode(', ') ?? '')
                        ->placeholder('Nenhuma operação vinculada.')
                        ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false),
                ]),

            Section::make('Saldo de referência manual')
                ->description('Versão vigente e histórico. Aprovações vinculadas mantêm a versão original; um novo registro nunca reescreve o passado.')
                ->columns(3)
                ->schema([
                    TextEntry::make('reference_amount')
                        ->label('Valor vigente')
                        ->state(fn (FundAllocationPeriod $record): string => static::moneyOrDash(static::currentSnapshot($record)?->amount))
                        ->fontFamily('mono')
                        ->weight('semibold')
                        ->alignment('end'),

                    TextEntry::make('reference_version')
                        ->label('Versão vigente')
                        ->state(fn (FundAllocationPeriod $record): string => static::currentSnapshot($record) !== null ? 'v'.static::currentSnapshot($record)->version : '—'),

                    TextEntry::make('reference_source')
                        ->label('Origem')
                        ->state(fn (FundAllocationPeriod $record): string => static::currentSnapshot($record)?->sourceLabel() ?? '—'),

                    TextEntry::make('reference_captured_at')
                        ->label('Data de referência')
                        ->state(fn (FundAllocationPeriod $record): string => static::currentSnapshot($record)?->captured_at?->format('d/m/Y') ?? '—'),

                    TextEntry::make('reference_recorded_by')
                        ->label('Registrado por')
                        ->state(fn (FundAllocationPeriod $record): string => static::currentSnapshot($record)?->recordedBy?->name ?? '—')
                        ->columnSpan(2),

                    TextEntry::make('reference_note')
                        ->label('Observação')
                        ->state(fn (FundAllocationPeriod $record): string => static::currentSnapshot($record)?->note ?? '')
                        ->placeholder('—')
                        ->columnSpanFull(),

                    RepeatableEntry::make('reference_history')
                        ->label('Histórico de versões')
                        ->columnSpanFull()
                        ->contained(false)
                        ->state(fn (FundAllocationPeriod $record): array => static::referenceHistory($record))
                        ->schema([
                            TextEntry::make('version')->label('Versão')->fontFamily('mono'),
                            TextEntry::make('amount')->label('Valor')->fontFamily('mono')->alignment('end'),
                            TextEntry::make('captured_at')->label('Data de referência'),
                            TextEntry::make('recorded_by')->label('Registrado por'),
                        ]),
                ]),

            Section::make('Resumo financeiro')
                ->description('Totais calculados pelo servidor sobre a revisão exibida.')
                ->columns(3)
                ->schema([
                    TextEntry::make('summary_reference')
                        ->label('Saldo de referência')
                        ->state(fn (FundAllocationPeriod $record): string => static::moneyOrDash(static::displayRevision($record)?->reference_amount))
                        ->fontFamily('mono')
                        ->alignment('end'),

                    TextEntry::make('summary_total')
                        ->label('Total alocado')
                        ->state(fn (FundAllocationPeriod $record): string => static::moneyOrDash(static::displayRevision($record)?->total_amount))
                        ->fontFamily('mono')
                        ->weight('semibold')
                        ->alignment('end'),

                    TextEntry::make('summary_unallocated')
                        ->label('Não alocado')
                        ->state(fn (FundAllocationPeriod $record): string => static::moneyOrDash(static::displayRevision($record)?->unallocated_amount))
                        ->fontFamily('mono')
                        ->weight('semibold')
                        ->alignment('end'),

                    TextEntry::make('summary_allocated_pct')
                        ->label('Alocado (%)')
                        ->state(fn (FundAllocationPeriod $record): string => static::shareLabel($record, allocated: true)),

                    TextEntry::make('summary_unallocated_pct')
                        ->label('Não alocado (%)')
                        ->state(fn (FundAllocationPeriod $record): string => static::shareLabel($record, allocated: false)),

                    TextEntry::make('summary_previous')
                        ->label('Competência anterior aprovada')
                        ->state(fn (FundAllocationPeriod $record): string => static::previousApprovedLabel($record))
                        ->columnSpanFull(),
                ]),

            Section::make('Revisão em aberto')
                ->visible(fn (FundAllocationPeriod $record): bool => $record->openRevision instanceof FundAllocationRevision)
                ->columns(3)
                ->schema([
                    TextEntry::make('open_status')
                        ->label('Situação')
                        ->state(fn (FundAllocationPeriod $record): string => $record->openRevision?->statusLabel() ?? '—')
                        ->badge()
                        ->color(fn (FundAllocationPeriod $record): string => $record->openRevision?->status === FundAllocationRevision::STATUS_PENDING_REVIEW ? 'warning' : 'gray'),

                    TextEntry::make('open_reference_version')
                        ->label('Versão de referência vinculada')
                        ->state(fn (FundAllocationPeriod $record): string => $record->openRevision?->snapshot !== null ? 'v'.$record->openRevision->snapshot->version : '—'),

                    TextEntry::make('open_responsibles')
                        ->label('Responsáveis')
                        ->state(fn (FundAllocationPeriod $record): string => static::openResponsibles($record))
                        ->columnSpan(2),

                    TextEntry::make('open_correction_reason')
                        ->label('Motivo da correção')
                        ->state(fn (FundAllocationPeriod $record): string => $record->openRevision?->correction_reason ?? '')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->visible(fn (FundAllocationPeriod $record): bool => $record->openRevision?->isCorrection() ?? false),

                    RepeatableEntry::make('open_lines')
                        ->label('Linhas da revisão em aberto')
                        ->columnSpanFull()
                        ->contained(false)
                        ->state(fn (FundAllocationPeriod $record): array => static::lineRows($record->openRevision))
                        ->schema(static::lineSchema()),
                ]),

            Section::make('Revisão vigente (aprovada)')
                ->visible(fn (FundAllocationPeriod $record): bool => $record->effectiveRevision instanceof FundAllocationRevision)
                ->columns(3)
                ->schema([
                    TextEntry::make('effective_status')
                        ->label('Situação')
                        ->state(fn (): string => 'Aprovada')
                        ->badge()
                        ->color('success'),

                    TextEntry::make('effective_reference_version')
                        ->label('Versão de referência vinculada')
                        ->state(fn (FundAllocationPeriod $record): string => $record->effectiveRevision?->snapshot !== null ? 'v'.$record->effectiveRevision->snapshot->version : '—'),

                    TextEntry::make('effective_responsibles')
                        ->label('Responsáveis')
                        ->state(fn (FundAllocationPeriod $record): string => static::effectiveResponsibles($record))
                        ->columnSpan(2),

                    RepeatableEntry::make('effective_lines')
                        ->label('Linhas aprovadas (somente leitura)')
                        ->columnSpanFull()
                        ->contained(false)
                        ->state(fn (FundAllocationPeriod $record): array => static::lineRows($record->effectiveRevision))
                        ->schema(static::lineSchema()),
                ]),

            Section::make('Sem alocação para esta competência')
                ->visible(fn (FundAllocationPeriod $record): bool => ! $record->hasOpenRevision() && ! $record->hasEffectiveRevision())
                ->schema([
                    TextEntry::make('no_allocation_note')
                        ->hiddenLabel()
                        ->state(fn (): string => 'Nenhum rascunho ou aprovação. Use “Nova proposta” para iniciar a distribuição sobre o saldo de referência vigente.')
                        ->color('gray'),
                ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function lineSchema(): array
    {
        return [
            TextEntry::make('emission')
                ->label('Operação')
                ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false),
            TextEntry::make('fund')
                ->label('Fundo')
                ->weight('semibold'),
            TextEntry::make('classification')
                ->label('Classificação'),
            TextEntry::make('amount')
                ->label('Valor alocado')
                ->fontFamily('mono')
                ->alignment('end'),
            TextEntry::make('share')
                ->label('Parcela da referência')
                ->fontFamily('mono')
                ->alignment('end'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function lineRows(?FundAllocationRevision $revision): array
    {
        if (! $revision instanceof FundAllocationRevision) {
            return [];
        }

        $revision->loadMissing(['lines.fund.fundName', 'lines.fund.fundApplication', 'lines.emission']);

        $referenceCents = static::centsOrZero($revision->reference_amount);

        return $revision->lines
            ->sortBy([
                fn ($a, $b): int => strcmp((string) ($a->emission?->name ?? ''), (string) ($b->emission?->name ?? '')),
                fn ($a, $b): int => strcmp((string) ($a->fund?->fundName?->name ?? ''), (string) ($b->fund?->fundName?->name ?? '')),
            ])
            ->values()
            ->map(function ($line) use ($referenceCents): array {
                $cents = static::centsOrZero($line->amount);

                return [
                    'emission' => $line->emission?->name ?? "Operação #{$line->emission_id}",
                    'fund' => ($line->fund?->fundName?->name ?? "Fundo #{$line->fund_id}")." · #{$line->fund_id}",
                    'classification' => $line->fund?->fundApplication?->name ?? '—',
                    'amount' => FundAllocationDraftLines::formatCents($cents),
                    'share' => FundAllocationDraftLines::percentage($cents, $referenceCents),
                ];
            })
            ->all();
    }

    public static function displayRevision(FundAllocationPeriod $record): ?FundAllocationRevision
    {
        return $record->openRevision ?? $record->effectiveRevision;
    }

    private static function currentSnapshot(FundAllocationPeriod $record): ?BankApplicationBalanceSnapshot
    {
        static $cache = [];

        $key = $record->getKey();

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = BankApplicationBalanceSnapshot::query()
                ->where('bank_application_id', $record->bank_application_id)
                ->whereDate('competence', $record->competence?->toDateString())
                ->orderByDesc('version')
                ->with('recordedBy')
                ->first();
        }

        return $cache[$key];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function referenceHistory(FundAllocationPeriod $record): array
    {
        return BankApplicationBalanceSnapshot::query()
            ->where('bank_application_id', $record->bank_application_id)
            ->whereDate('competence', $record->competence?->toDateString())
            ->orderByDesc('version')
            ->with('recordedBy')
            ->get()
            ->map(fn (BankApplicationBalanceSnapshot $snapshot): array => [
                'version' => "v{$snapshot->version}",
                'amount' => FundAllocationDraftLines::formatCents(
                    FundAllocationMoney::toCents((string) $snapshot->amount)
                ),
                'captured_at' => $snapshot->captured_at?->format('d/m/Y') ?? '—',
                'recorded_by' => $snapshot->recordedBy?->name ?? '—',
            ])
            ->all();
    }

    private static function shareLabel(FundAllocationPeriod $record, bool $allocated): string
    {
        $revision = static::displayRevision($record);

        if (! $revision instanceof FundAllocationRevision) {
            return '—';
        }

        $part = static::centsOrZero($allocated ? $revision->total_amount : $revision->unallocated_amount);

        return FundAllocationDraftLines::percentage($part, static::centsOrZero($revision->reference_amount));
    }

    private static function previousApprovedLabel(FundAllocationPeriod $record): string
    {
        $period = FundAllocationPeriod::query()
            ->where('bank_application_id', $record->bank_application_id)
            ->whereDate('competence', '<', $record->competence?->toDateString())
            ->whereNotNull('effective_revision_id')
            ->orderByDesc('competence')
            ->first();

        if (! $period instanceof FundAllocationPeriod) {
            return 'Sem competência anterior aprovada para esta aplicação.';
        }

        $revision = FundAllocationRevision::query()
            ->whereKey($period->effective_revision_id)
            ->where('status', FundAllocationRevision::STATUS_APPROVED)
            ->first();

        if (! $revision instanceof FundAllocationRevision) {
            return 'Sem competência anterior aprovada para esta aplicação.';
        }

        $competence = $period->competence?->format('m/Y') ?? '—';

        return sprintf(
            '%s · alocado %s · não alocado %s',
            $competence,
            static::moneyOrDash($revision->total_amount),
            static::moneyOrDash($revision->unallocated_amount),
        );
    }

    private static function openResponsibles(FundAllocationPeriod $record): string
    {
        $revision = $record->openRevision;

        if (! $revision instanceof FundAllocationRevision) {
            return '—';
        }

        $revision->loadMissing(['proposedBy', 'submittedBy']);

        $parts = [];

        if ($revision->proposedBy?->name !== null) {
            $parts[] = 'Proposta por '.$revision->proposedBy->name;
        }

        if ($revision->submittedBy?->name !== null) {
            $parts[] = 'Enviada por '.$revision->submittedBy->name
                .($revision->submitted_at !== null ? ' em '.$revision->submitted_at->format('d/m/Y H:i') : '');
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    private static function effectiveResponsibles(FundAllocationPeriod $record): string
    {
        $revision = $record->effectiveRevision;

        if (! $revision instanceof FundAllocationRevision) {
            return '—';
        }

        $revision->loadMissing(['proposedBy', 'approvedBy']);

        $parts = [];

        if ($revision->proposedBy?->name !== null) {
            $parts[] = 'Proposta por '.$revision->proposedBy->name;
        }

        if ($revision->approvedBy?->name !== null) {
            $parts[] = 'Aprovada por '.$revision->approvedBy->name
                .($revision->approved_at !== null ? ' em '.$revision->approved_at->format('d/m/Y H:i') : '');
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    private static function moneyOrDash(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            return FundAllocationDraftLines::formatCents(FundAllocationMoney::toCents((string) $value));
        } catch (\InvalidArgumentException) {
            return '—';
        }
    }

    private static function centsOrZero(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        try {
            return FundAllocationMoney::toCents((string) $value);
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }
}
