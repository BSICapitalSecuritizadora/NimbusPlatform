<?php

namespace App\Filament\Resources\Proposals\RelationManagers;

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\ProposalContinuationAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
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
            ->searchPlaceholder('Buscar por destinatário ou e-mail...')
            ->columns([
                TextColumn::make('proposal.contact.name')
                    ->label('Destinatário')
                    ->placeholder('—')
                    ->searchable(['proposal.contact.name', 'sent_to_email'])
                    ->description(fn (ProposalContinuationAccess $record): string => $record->sent_to_email),
                TextColumn::make('display_code')
                    ->label('Código')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable(fn (ProposalContinuationAccess $record): bool => filled($record->decrypted_code))
                    ->copyMessage('Código copiado'),
                TextColumn::make('generated_url')
                    ->label('Link de Acesso')
                    ->formatStateUsing(fn (): string => 'Link seguro gerado')
                    ->icon('heroicon-m-link')
                    ->iconColor('primary')
                    ->copyable()
                    ->copyMessage('Link de acesso copiado')
                    ->tooltip('Copiar link de acesso'),
                TextColumn::make('expires_at')
                    ->label('Validade')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->description(fn (ProposalContinuationAccess $record): ?string => match (true) {
                        $record->expires_at === null => null,
                        $record->expires_at->isPast() => 'Expirado '.$record->expires_at->diffForHumans(),
                        default => 'Expira '.$record->expires_at->diffForHumans(),
                    })
                    ->sortable(),
                TextColumn::make('status_label')
                    ->label('Situação')
                    ->badge()
                    ->color(fn (ProposalContinuationAccess $record): string => $record->status_color),
                TextColumn::make('sent_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('mail_queued_at')
                    ->label('Enfileirado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mail_failed_at')
                    ->label('Falha no Envio')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('first_accessed_at')
                    ->label('Primeiro Acesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_accessed_at')
                    ->label('Último Acesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('verified_at')
                    ->label('Data de Validação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                $this->resendAccessAction(),
            ])
            ->emptyStateIcon('heroicon-o-link')
            ->emptyStateHeading('Nenhum link de acesso gerado')
            ->emptyStateDescription('Gere ou envie o primeiro acesso para disponibilizar o preenchimento ao destinatário.')
            ->emptyStateActions([
                $this->resendAccessAction(),
            ])
            ->actions([
                Action::make('open_link')
                    ->label('Abrir Link')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (RelationManager $livewire): bool => ProposalResource::canEdit($livewire->getOwnerRecord()))
                    ->url(fn (ProposalContinuationAccess $record): string => $record->generated_url)
                    ->openUrlInNewTab(),
                Action::make('view_link')
                    ->label('Ver Link Completo')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (RelationManager $livewire): bool => ProposalResource::canEdit($livewire->getOwnerRecord()))
                    ->modalHeading('Link de Acesso Completo')
                    ->modalDescription('URL assinada do acesso. Trate-a como uma credencial: compartilhe somente com o destinatário da proposta.')
                    ->modalContent(fn (ProposalContinuationAccess $record) => view('filament.proposals.continuation-access-link', [
                        'url' => $record->generated_url,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    protected function resendAccessAction(): Action
    {
        return Action::make('resend_access')
            ->label('Reenviar Link de Acesso')
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->visible(fn (RelationManager $livewire): bool => ProposalResource::canEdit($livewire->getOwnerRecord()))
            ->action(function (): void {
                app(SendProposalContinuationLink::class)->handle(
                    $this->getOwnerRecord()->loadMissing(['company', 'contact']),
                );

                Notification::make()
                    ->title('Novo link e código gerados; envio de e-mail enfileirado.')
                    ->success()
                    ->send();
            });
    }
}
