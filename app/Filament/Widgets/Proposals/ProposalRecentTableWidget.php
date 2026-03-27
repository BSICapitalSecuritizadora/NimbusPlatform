<?php

namespace App\Filament\Widgets\Proposals;

use App\Models\Proposal;
use App\Support\Proposals\ProposalDashboardData;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ProposalRecentTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Propostas recentes';

    public function table(Table $table): Table
    {
        return $table
            ->query(app(ProposalDashboardData::class)->recentQuery())
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
                    ->formatStateUsing(fn (?string $state): string => Proposal::statusLabelFor($state))
                    ->color(fn (?string $state): string => Proposal::statusColorFor($state)),
                TextColumn::make('created_at')
                    ->label('Entrada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
