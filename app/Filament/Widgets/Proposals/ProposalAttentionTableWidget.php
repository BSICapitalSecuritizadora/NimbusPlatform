<?php

namespace App\Filament\Widgets\Proposals;

use App\Models\Proposal;
use App\Support\Proposals\ProposalDashboardData;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ProposalAttentionTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Propostas que precisam de atenção';

    public function table(Table $table): Table
    {
        return $table
            ->query(app(ProposalDashboardData::class)->attentionQuery())
            ->recordUrl(fn (Proposal $record): string => route('filament.admin.resources.proposals.view', ['record' => $record]))
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10])
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Proposal::statusLabelFor($state))
                    ->color(fn (?string $state): string => Proposal::statusColorFor($state)),
                TextColumn::make('attention_reason')
                    ->label('Motivo')
                    ->state(fn (Proposal $record): string => app(ProposalDashboardData::class)->attentionReason($record))
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->label('Última atualização')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
