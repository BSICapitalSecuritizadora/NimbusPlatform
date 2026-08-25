<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Tables;

use App\Services\Nimbus\NimbusNotificationService;
use App\Services\Security\PiiPseudonymizer;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PortalUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('Nome Completo')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('document_number')
                    ->label('CPF')
                    ->formatStateUsing(fn (?string $state): ?string => self::formatCpfForDisplay($state))
                    ->searchable(false)
                    ->toggleable(),
                TextColumn::make('phone_number')
                    ->label('Telefone')
                    ->formatStateUsing(fn (?string $state): ?string => self::formatPhoneForDisplay($state))
                    ->searchable(false)
                    ->toggleable(),
                TextColumn::make('external_id')
                    ->label('ID Externo')
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
                    ->label('Último Acesso')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_login_method')
                    ->label('Método de Autenticação')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Data de Criação')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Última Atualização')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'INVITED' => 'Aguardando Cadastro',
                        'ACTIVE' => 'Ativo',
                        'INACTIVE' => 'Inativo',
                        'BLOCKED' => 'Suspenso',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('generate_token')
                    ->label('Gerar Chave de Acesso')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->visible(fn ($record): bool => filled($record->email) && (
                        auth()->user()?->can('nimbus.access-tokens.create')
                        || auth()->user()?->can('nimbus.portal-users.update')
                    ))
                    ->requiresConfirmation()
                    ->modalHeading('Gerar Chave de Acesso')
                    ->modalDescription('Uma nova chave de acesso será gerada (formato XXXX-XXXX-XXXX) e a chave anterior, se houver, será revogada. O código será gerado e enviado de forma assíncrona via fila segura.')
                    ->action(function ($record): void {
                        try {
                            $service = app(NimbusNotificationService::class);
                            $outbox = $service->enqueueAccessCode($record);

                            if (! $outbox) {
                                throw new \RuntimeException('Não foi possível enfileirar o envio. Verifique o e-mail do usuário.');
                            }

                            // Audit: delivery requested (no plaintext).
                            activity('nimbus')
                                ->performedOn($record)
                                ->causedBy(auth()->user())
                                ->withProperties([
                                    'portal_user_id' => $record->id,
                                    'email_hash' => PiiPseudonymizer::email($record->email),
                                    'outbox_id' => $outbox->id,
                                    'correlation_id' => $outbox->correlation_id,
                                ])
                                ->log('nimbus.access_token.delivery_requested');

                            Notification::make()
                                ->title('Chave de Acesso Enfileirada')
                                ->body('A geração e o envio foram enfileirados com segurança. O portal usuário receberá o código em instantes. Acompanhe em Auditoria de Envios.')
                                ->success()
                                ->duration(10000)
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erro ao Enfileirar Chave')
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

    private static function normalizeDigits(?string $state): ?string
    {
        if (! filled($state)) {
            return null;
        }

        return preg_replace('/\D+/', '', $state);
    }

    private static function formatCpfForDisplay(?string $state): ?string
    {
        $digits = self::normalizeDigits($state);

        if (! filled($digits) || strlen($digits) !== 11) {
            return $state;
        }

        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits);
    }

    private static function formatPhoneForDisplay(?string $state): ?string
    {
        $digits = self::normalizeDigits($state);

        if (! filled($digits)) {
            return $state;
        }

        if (strlen($digits) === 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $digits);
        }

        if (strlen($digits) === 11) {
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $digits);
        }

        return $state;
    }
}
