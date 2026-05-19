<?php

namespace App\Filament\Resources\Proposals\RelationManagers;

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\ProposalContinuationAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProposalContinuationAccessRelationManager extends RelationManager
{
    protected static string $relationship = 'continuationAccesses';

    protected static ?string $title = 'Controle de Links e Códigos';

    protected static ?string $modelLabel = 'Envio de Acesso';

    protected static ?string $pluralModelLabel = 'Envios de Acesso';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'proposal.contact',
            ]))
            ->recordTitleAttribute('sent_to_email')
            ->columns([
                TextColumn::make('proposal.contact.name')
                    ->label('Usuário Solicitante')
                    ->placeholder('—'),
                TextColumn::make('sent_to_email')
                    ->label('E-mail de Destino')
                    ->searchable(),
                TextColumn::make('display_code')
                    ->label('Código Gerado'),
                TextColumn::make('generated_url')
                    ->label('Link de Acesso')
                    ->wrap(),
                TextColumn::make('status_label')
                    ->label('Situação')
                    ->badge()
                    ->color(fn (ProposalContinuationAccess $record): string => $record->status_color),
                TextColumn::make('sent_at')
                    ->label('Data de Envio')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('first_accessed_at')
                    ->label('Primeiro Acesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_accessed_at')
                    ->label('Último Acesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('verified_at')
                    ->label('Data de Validação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('resend_access')
                    ->label('Reenviar Link de Acesso')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->visible(fn (RelationManager $livewire): bool => ProposalResource::canEdit($livewire->getOwnerRecord()))
                    ->action(function (): void {
                        app(SendProposalContinuationLink::class)->handle(
                            $this->getOwnerRecord()->loadMissing(['company', 'contact']),
                        );

                        Notification::make()
                            ->title('Novo link e código enviados com sucesso.')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Action::make('open_link')
                    ->label('Abrir Link')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (RelationManager $livewire): bool => ProposalResource::canEdit($livewire->getOwnerRecord()))
                    ->url(fn (ProposalContinuationAccess $record): string => $record->generated_url)
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([])
            ->defaultSort('sent_at', 'desc');
    }
}
