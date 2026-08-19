<?php

namespace App\Filament\Resources\Proposals\Tables;

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Proposal;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProposalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Proposal $record): string => ProposalResource::getUrl('view', ['record' => $record]))
            ->searchPlaceholder('Buscar por proponente, CNPJ, contato ou responsável...')
            ->defaultSort('distribution_sequence', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhuma proposta encontrada')
            ->emptyStateDescription('Não há propostas cadastradas nesta etapa ou que correspondam aos filtros aplicados.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->columns([
                TextColumn::make('distribution_sequence')
                    ->label('Pos.')
                    ->tooltip('Ordem na fila de distribuição comercial')
                    ->formatStateUsing(fn (?int $state): string => filled($state) ? (string) $state : '—')
                    ->weight('semibold')
                    ->color('primary')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('company.name')
                    ->label('Proponente')
                    ->description(fn (Proposal $record): ?string => $record->company?->cnpj)
                    ->weight('semibold')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'company',
                        fn (Builder $companyQuery): Builder => $companyQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('cnpj', 'like', "%{$search}%"),
                    ))
                    ->sortable()
                    ->limit(45)
                    ->tooltip(fn (Proposal $record): ?string => $record->company?->name),

                TextColumn::make('contact.name')
                    ->label('Contato Comercial')
                    ->description(fn (Proposal $record): ?string => $record->contact?->email)
                    ->placeholder('Não informado')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('representative.name')
                    ->label('Responsável')
                    ->placeholder('Não atribuído')
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray')
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProposalStatus::labelFor($state))
                    ->color(fn (?string $state): string => ProposalStatus::colorFor($state))
                    ->sortable(),

                TextColumn::make('latestContinuationAccess.status_label')
                    ->label('Link Seguro')
                    ->state(fn (Proposal $record): string => $record->latestContinuationAccess?->status_label ?? 'Sem envio')
                    ->badge()
                    ->color(fn (Proposal $record): string => $record->latestContinuationAccess?->status_color ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Data de Entrada')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Proposal $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('latestStatusHistory.changed_at')
                    ->label('Última Atualização')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Proposal $record): ?string => $record->latestStatusHistory?->changed_at?->diffForHumans())
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('distributed_at')
                    ->label('Distribuição')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('completed_at')
                    ->label('Formalização')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação da Proposta')
                    ->options(ProposalStatus::options()),

                SelectFilter::make('assigned_representative_id')
                    ->label('Representante Comercial')
                    ->relationship('representative', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar Dossiê')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(fn (Proposal $record): bool => ProposalResource::canView($record)),

                    ProposalResource::getChangeStatusAction(),

                    Action::make('resend_access')
                        ->label('Reenviar Link de Acesso')
                        ->icon('heroicon-o-paper-airplane')
                        ->requiresConfirmation()
                        ->visible(fn (Proposal $record): bool => ProposalResource::canEdit($record)
                            && filled($record->contact?->email))
                        ->action(function (Proposal $record): void {
                            app(SendProposalContinuationLink::class)->handle(
                                $record->loadMissing(['company', 'contact']),
                            );

                            Notification::make()
                                ->title('Novo link de acesso enviado ao cliente com sucesso.')
                                ->success()
                                ->send();
                        }),

                    EditAction::make()
                        ->label('Editar Notas Internas')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (Proposal $record): bool => ProposalResource::canEdit($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da proposta'),
            ]);
    }
}
