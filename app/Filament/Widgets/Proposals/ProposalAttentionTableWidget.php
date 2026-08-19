<?php

namespace App\Filament\Widgets\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Support\Proposals\ProposalDashboardData;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ProposalAttentionTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Propostas com Atenção / SLA Crítico';

    public function table(Table $table): Table
    {
        $dashboardData = app(ProposalDashboardData::class);

        return $table
            ->description('Fila de exceções: propostas com pendências documentais ou estagnação operacional.')
            ->query($dashboardData->attentionQuery())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'company',
                'representative',
                'latestStatusHistory.changedByUser',
            ]))
            ->recordUrl(fn (Proposal $record): string => route('filament.admin.resources.proposals.view', ['record' => $record]))
            ->searchPlaceholder('Buscar por proponente, CNPJ ou responsável...')
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->emptyStateHeading('Nenhuma proposta exige atenção no momento')
            ->emptyStateDescription('Todas as propostas da carteira estão em dia, com documentação regular e movimentação recente.')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->columns([
                TextColumn::make('company.name')
                    ->label('Proponente')
                    ->description(fn (Proposal $record): ?string => $record->company?->cnpj)
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $subQuery) use ($search): void {
                        $subQuery
                            ->whereHas('company', fn (Builder $companyQuery): Builder => $companyQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('cnpj', 'like', "%{$search}%"))
                            ->orWhereHas('representative', fn (Builder $representativeQuery): Builder => $representativeQuery
                                ->where('name', 'like', "%{$search}%"));
                    }))
                    ->limit(45)
                    ->tooltip(fn (Proposal $record): ?string => $record->company?->name),

                TextColumn::make('representative.name')
                    ->label('Responsável')
                    ->placeholder('Não atribuído')
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray')
                    ->color('gray')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProposalStatus::labelFor($state))
                    ->color(fn (?string $state): string => ProposalStatus::colorFor($state)),

                TextColumn::make('severity')
                    ->label('Diagnóstico & SLA')
                    ->state(fn (Proposal $record): string => $dashboardData->attentionSeverityLabel($record))
                    ->badge()
                    ->color(fn (Proposal $record): string => $dashboardData->attentionSeverityColor($record))
                    ->icon(fn (Proposal $record): string => $dashboardData->attentionSeverityIcon($record))
                    ->description(fn (Proposal $record): string => $dashboardData->attentionDiagnosis($record)),

                TextColumn::make('updated_at')
                    ->label('Última Movimentação')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Proposal $record): ?string => $record->updated_at?->diffForHumans())
                    ->sortable(),
            ])
            ->filtersFormWidth(Width::ExtraSmall)
            ->filtersFormMaxHeight('420px')
            ->filters([
                SelectFilter::make('severity')
                    ->label('Criticidade')
                    ->options([
                        'critical' => 'SLA Crítico',
                        'attention' => 'Atenção',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'critical' => $query->where(function (Builder $q) use ($dashboardData): void {
                            $q->where(function (Builder $sub) use ($dashboardData): void {
                                $sub->where('status', ProposalStatus::InReview->value)
                                    ->where('updated_at', '<=', $dashboardData->criticalReviewThreshold());
                            })->orWhere(function (Builder $sub) use ($dashboardData): void {
                                $sub->whereIn('status', [
                                    ProposalStatus::AwaitingCompletion->value,
                                    ProposalStatus::AwaitingInformation->value,
                                ])->where('updated_at', '<=', $dashboardData->criticalPendingThreshold());
                            });
                        }),
                        'attention' => $query->where(function (Builder $q) use ($dashboardData): void {
                            $q->where(function (Builder $sub) use ($dashboardData): void {
                                $sub->where('status', ProposalStatus::InReview->value)
                                    ->where('updated_at', '>', $dashboardData->criticalReviewThreshold());
                            })->orWhere(function (Builder $sub) use ($dashboardData): void {
                                $sub->whereIn('status', [
                                    ProposalStatus::AwaitingCompletion->value,
                                    ProposalStatus::AwaitingInformation->value,
                                ])->where('updated_at', '>', $dashboardData->criticalPendingThreshold());
                            });
                        }),
                        default => $query,
                    }),

                SelectFilter::make('assigned_representative_id')
                    ->label('Responsável')
                    ->relationship('representative', 'name')
                    ->searchable()
                    ->preload(),
            ]);
    }
}
