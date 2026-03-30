<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PortalUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('document_number')
                    ->searchable(),
                TextColumn::make('phone_number')
                    ->searchable(),
                TextColumn::make('external_id')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('last_login_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_login_method')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                \Filament\Tables\Actions\Action::make('generate_token')
                    ->label('Gerar Código')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Gerar Código de Acesso')
                    ->modalDescription('Isso irá invalidar o código atual do usuário (caso exista) e gerar um novo código de 14 dígitos.')
                    ->action(function ($record) {
                        try {
                            \Illuminate\Support\Facades\DB::transaction(function () use ($record, &$code) {
                                // Invalidar tokens anteriores do usuário
                                $record->accessTokens()->update(['status' => 'expired']);

                                // Gerar novo formato XXXX-XXXX-XXXX
                                $code = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)).'-'.
                                        strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)).'-'.
                                        strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

                                $record->accessTokens()->create([
                                    'token' => $code,
                                    'status' => 'active',
                                    'expires_at' => now()->addDays(7),
                                ]);
                            });

                            // Disparar o email para a Fila (Queue)
                            \Illuminate\Support\Facades\Mail::to($record->email)
                                ->send(new \App\Mail\Nimbus\SendPortalAccessCode($record, $code));

                            \Filament\Notifications\Notification::make()
                                ->title('Código Enviado!')
                                ->body("O código **{$code}** foi gerado e as instruções foram enviadas para o e-mail do usuário.")
                                ->success()
                                ->duration(10000)
                                ->send();

                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Erro ao gerar código')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
