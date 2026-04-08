<?php

namespace App\Filament\Widgets\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Support\Proposals\ProposalDashboardData;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ProposalRecentTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Propostas recentes';

    public function table(Table $table): Table
    {
        return $table
            ->query(app(ProposalDashboardData::class)->recentQuery())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'company',
                'representative',
                'latestStatusHistory.changedByUser',
            ]))
            ->recordUrl(fn (Proposal $record): string => route('filament.admin.resources.proposals.view', ['record' => $record]))
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10])
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('representative.name')
                    ->label('Representante')
                    ->placeholder('Não atribuído'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProposalStatus::labelFor($state))
                    ->color(fn (?string $state): string => ProposalStatus::colorFor($state)),
                TextColumn::make('created_at')
                    ->label('Entrada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
