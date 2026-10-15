<?php

namespace App\Filament\Resources\FundAllocations\Tables;

use App\Filament\Resources\FundAllocations\FundAllocationResource;
use App\Filament\Resources\FundAllocations\Schemas\FundAllocationDraftLines;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\Emission;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Support\Funds\FundAllocationMoney;
use Carbon\Carbon;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FundAllocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (FundAllocationPeriod $record): ?string => FundAllocationResource::getUrl('view', ['record' => $record]))
            ->defaultSort('competence', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhuma alocação por competência')
            ->emptyStateDescription('Crie a primeira alocação para distribuir o saldo de uma aplicação entre os fundos vinculados.')
            ->emptyStateIcon('heroicon-o-scale')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeira alocação')
                    ->icon('heroicon-m-plus')
                    ->color('gray'),
            ])
            ->columns([
                TextColumn::make('competence')
                    ->label('Competência')
                    ->state(fn (FundAllocationPeriod $record): string => $record->competence?->format('m/Y') ?? '—')
                    ->weight('semibold')
                    ->sortable(),

                TextColumn::make('bankApplication.optionLabel')
                    ->label('Aplicação')
                    ->state(fn (FundAllocationPeriod $record): string => $record->bankApplication?->optionLabel() ?? '—')
                    ->fontFamily('mono')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('shared_application')
                    ->label('Uso')
                    ->state(fn (FundAllocationPeriod $record): string => ($record->bankApplication !== null && $record->bankApplication->isShared()) ? 'Compartilhado' : '—')
                    ->badge(fn (FundAllocationPeriod $record): bool => $record->bankApplication !== null && $record->bankApplication->isShared())
                    ->color('warning')
                    ->tooltip(fn (FundAllocationPeriod $record): ?string => ($record->bankApplication !== null && $record->bankApplication->isShared())
                        ? 'A aplicação física atende a fundos de mais de uma operação.'
                        : null),

                TextColumn::make('current_reference')
                    ->label('Referência')
                    ->state(fn (FundAllocationPeriod $record): string => static::currentReferenceLabel($record))
                    ->fontFamily('mono')
                    ->alignment('end')
                    ->tooltip('Saldo de referência manual vigente.'),

                TextColumn::make('allocated_amount')
                    ->label('Alocado')
                    ->state(fn (FundAllocationPeriod $record): string => static::moneyOrDash(static::displayRevision($record)?->total_amount))
                    ->fontFamily('mono')
                    ->alignment('end'),

                TextColumn::make('unallocated_amount')
                    ->label('Não alocado')
                    ->state(fn (FundAllocationPeriod $record): string => static::moneyOrDash(static::displayRevision($record)?->unallocated_amount))
                    ->fontFamily('mono')
                    ->alignment('end')
                    ->weight('medium'),

                TextColumn::make('allocation_status')
                    ->label('Situação')
                    ->state(fn (FundAllocationPeriod $record): string => static::statusLabel($record))
                    ->badge()
                    ->color(fn (FundAllocationPeriod $record): string => static::statusColor($record)),

                TextColumn::make('responsibles')
                    ->label('Responsáveis')
                    ->state(fn (FundAllocationPeriod $record): string => static::responsiblesLabel($record))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('competence')
                    ->label('Competência')
                    ->options(fn (): array => FundAllocationPeriod::query()
                        ->orderByDesc('competence')
                        ->pluck('competence')
                        ->map(fn ($competence): string => Carbon::parse($competence)->format('m/Y'))
                        ->unique()
                        ->mapWithKeys(fn (string $label): array => [$label => $label])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $label = $data['value'] ?? null;

                        if (! is_string($label) || ! preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $label, $matches)) {
                            return $query;
                        }

                        return $query->whereDate('competence', sprintf('%s-%s-01', $matches[2], $matches[1]));
                    })
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                SelectFilter::make('bank_account_id')
                    ->label('Conta bancária')
                    ->relationship('bankApplication.bankAccount', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => $record->optionLabel())
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                SelectFilter::make('bank_application_id')
                    ->label('Aplicação bancária')
                    ->relationship('bankApplication', 'codigo_produto')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => $record->optionLabel())
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                SelectFilter::make('emission_id')
                    ->label('Operação')
                    ->options(fn (): array => Emission::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false)
                    ->query(function (Builder $query, array $data): Builder {
                        $emissionId = $data['value'] ?? null;

                        if ($emissionId === null || $emissionId === '') {
                            return $query;
                        }

                        return $query->whereHas('bankApplication.funds', fn (Builder $funds): Builder => $funds->where('emission_id', $emissionId));
                    })
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Abrir')
                    ->icon('heroicon-m-eye'),
            ]);
    }

    public static function displayRevision(FundAllocationPeriod $record): ?FundAllocationRevision
    {
        return $record->openRevision ?? $record->effectiveRevision;
    }

    public static function statusLabel(FundAllocationPeriod $record): string
    {
        if ($record->openRevision instanceof FundAllocationRevision) {
            return $record->openRevision->statusLabel();
        }

        if ($record->effectiveRevision instanceof FundAllocationRevision) {
            return 'Aprovada';
        }

        return 'Sem alocação';
    }

    public static function statusColor(FundAllocationPeriod $record): string
    {
        if ($record->openRevision instanceof FundAllocationRevision) {
            return match ($record->openRevision->status) {
                FundAllocationRevision::STATUS_DRAFT => 'gray',
                FundAllocationRevision::STATUS_PENDING_REVIEW => 'warning',
                default => 'gray',
            };
        }

        if ($record->effectiveRevision instanceof FundAllocationRevision) {
            return 'success';
        }

        return 'gray';
    }

    private static function currentReferenceLabel(FundAllocationPeriod $record): string
    {
        $snapshot = BankApplicationBalanceSnapshot::query()
            ->where('bank_application_id', $record->bank_application_id)
            ->whereDate('competence', $record->competence?->toDateString())
            ->orderByDesc('version')
            ->first();

        if (! $snapshot instanceof BankApplicationBalanceSnapshot) {
            return '—';
        }

        return FundAllocationDraftLines::formatCents(
            FundAllocationMoney::toCents((string) $snapshot->amount)
        )." · v{$snapshot->version}";
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

    private static function responsiblesLabel(FundAllocationPeriod $record): string
    {
        $names = [];

        $open = $record->openRevision;
        $effective = $record->effectiveRevision;

        if ($open instanceof FundAllocationRevision && $open->proposedBy?->name !== null) {
            $names[] = 'Prop.: '.$open->proposedBy->name;
        }

        if ($effective instanceof FundAllocationRevision && $effective->approvedBy?->name !== null) {
            $names[] = 'Aprov.: '.$effective->approvedBy->name;
        } elseif ($open instanceof FundAllocationRevision && $open->submittedBy?->name !== null) {
            $names[] = 'Env.: '.$open->submittedBy->name;
        }

        return $names === [] ? '—' : implode(' · ', array_unique($names));
    }
}
