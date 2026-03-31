<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PortalUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('Nome completo')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('document_number')
                    ->label('Documento')
                    ->searchable(),
                TextColumn::make('phone_number')
                    ->label('Telefone')
                    ->searchable(),
                TextColumn::make('external_id')
                    ->label('ID externo')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'INVITED' => 'Aguardando Cadastro',
                        'ACTIVE' => 'Ativo',
                        'INACTIVE' => 'Inativo',
                        'BLOCKED' => 'Suspenso',
                        default => (string) $state,
                    })
                    ->badge(),
                TextColumn::make('last_login_at')
                    ->label('Último acesso')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_login_method')
                    ->label('Método do último acesso')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('generate_token')
                    ->label('Gerar chave')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Gerar chave de acesso')
                    ->modalDescription('Isso irá revogar a chave atual do usuário, se existir, e gerar um novo código no formato XXXX-XXXX-XXXX.')
                    ->action(function ($record) {
                        try {
                            DB::transaction(function () use ($record, &$code) {
                                $record->accessTokens()
                                    ->where('status', 'PENDING')
                                    ->update(['status' => 'REVOKED']);

                                $code = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)).'-'.
                                    strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)).'-'.
                                    strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

                                $record->accessTokens()->create([
                                    'code' => $code,
                                    'status' => 'PENDING',
                                    'expires_at' => now()->addDays(7),
                                ]);
                            });

                            Mail::to($record->email)
                                ->send(new \App\Mail\Nimbus\SendPortalAccessCode($record, $code));

                            Notification::make()
                                ->title('Chave enviada')
                                ->body("A chave **{$code}** foi gerada e enviada para o e-mail do usuário.")
                                ->success()
                                ->duration(10000)
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erro ao gerar chave')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Excluir selecionados'),
                ]),
            ]);
    }
}
