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

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Entradas e Movimentações Recentes';

    public function table(Table $table): Table
    {
        return $table
            ->description('Últimos registros comerciais captados e distribuídos no sistema.')
            ->query(app(ProposalDashboardData::class)->recentQuery())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'company',
                'representative',
                'contact',
                'latestStatusHistory.changedByUser',
            ]))
            ->recordUrl(fn (Proposal $record): string => route('filament.admin.resources.proposals.view', ['record' => $record]))
            ->searchPlaceholder('Buscar por proponente, CNPJ ou responsável...')
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhuma entrada recente')
            ->emptyStateDescription('Novas propostas captadas aparecerão automaticamente aqui.')
            ->emptyStateIcon('heroicon-o-inbox')
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
                                ->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('contact', fn (Builder $contactQuery): Builder => $contactQuery
                                ->where('name', 'like', "%{$search}%"));
                    }))
                    ->limit(45)
                    ->tooltip(fn (Proposal $record): ?string => $record->company?->name),

                TextColumn::make('contact.name')
                    ->label('Contato Comercial')
                    ->description(fn (Proposal $record): ?string => $record->contact?->email)
                    ->placeholder('Não informado')
                    ->searchable(),

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

                TextColumn::make('created_at')
                    ->label('Entrada no Sistema')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Proposal $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable(),
            ]);
    }
}
