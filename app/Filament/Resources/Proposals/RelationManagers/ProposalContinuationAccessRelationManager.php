<?php

namespace App\Filament\Resources\Proposals\RelationManagers;

use App\Actions\Proposals\SendProposalContinuationLink;
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

    protected static ?string $title = 'Links e Codigos Enviados';

    protected static ?string $modelLabel = 'Envio';

    protected static ?string $pluralModelLabel = 'Envios';

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
                    ->label('Usuario')
                    ->placeholder('—'),
                TextColumn::make('sent_to_email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('display_code')
                    ->label('Codigo enviado'),
                TextColumn::make('generated_url')
                    ->label('Link gerado')
                    ->wrap(),
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (ProposalContinuationAccess $record): string => $record->status_color),
                TextColumn::make('sent_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('first_accessed_at')
                    ->label('Primeiro acesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_accessed_at')
                    ->label('Ultimo acesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('verified_at')
                    ->label('Validado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('resend_access')
                    ->label('Reenviar acesso')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        app(SendProposalContinuationLink::class)->handle(
                            $this->getOwnerRecord()->loadMissing(['company', 'contact']),
                        );

                        Notification::make()
                            ->title('Novo link e codigo enviados ao cliente.')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Action::make('open_link')
                    ->label('Abrir link')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ProposalContinuationAccess $record): string => $record->generated_url)
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([])
            ->defaultSort('sent_at', 'desc');
    }
}
