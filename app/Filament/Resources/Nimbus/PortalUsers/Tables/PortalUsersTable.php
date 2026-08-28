<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Tables;

use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use App\Models\Nimbus\PortalUser;
use App\Services\Nimbus\NimbusNotificationService;
use App\Services\Security\PiiPseudonymizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PortalUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('Usuário')
                    ->description(fn (PortalUser $record): ?string => $record->email)
                    ->searchable(['full_name', 'email'])
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->iconColor('primary')
                    ->wrap(false),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('E-mail copiado para a área de transferência')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('document_number')
                    ->label('CPF')
                    ->formatStateUsing(fn (?string $state): ?string => self::formatCpfForDisplay($state))
                    ->placeholder('—')
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(['class' => 'tabular-nums whitespace-nowrap text-xs text-slate-300'])
                    ->searchable(false)
                    ->toggleable(),
                TextColumn::make('phone_number')
                    ->label('Telefone')
                    ->formatStateUsing(fn (?string $state): ?string => self::formatPhoneForDisplay($state))
                    ->placeholder('—')
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(['class' => 'tabular-nums whitespace-nowrap text-xs text-slate-400'])
                    ->searchable(false)
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'INVITED' => 'Aguardando Cadastro',
                        'ACTIVE' => 'Ativo',
                        'INACTIVE' => 'Inativo',
                        'BLOCKED' => 'Suspenso',
                        default => (string) $state,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'ACTIVE' => 'success',
                        'INVITED' => 'warning',
                        'BLOCKED' => 'danger',
                        'INACTIVE' => 'gray',
                        default => 'gray',
                    })
                    ->icon(fn (?string $state): string|BackedEnum|null => match ($state) {
                        'ACTIVE' => Heroicon::OutlinedCheckCircle,
                        'INVITED' => Heroicon::OutlinedClock,
                        'BLOCKED' => Heroicon::OutlinedNoSymbol,
                        'INACTIVE' => Heroicon::OutlinedMinusCircle,
                        default => null,
                    })
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label('Último Acesso')
                    ->dateTime('d/m/Y · H:i')
                    ->placeholder('Nunca acessou')
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(['class' => 'tabular-nums whitespace-nowrap text-xs text-slate-300'])
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_login_method')
                    ->label('Método de Autenticação')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => match (strtoupper((string) $state)) {
                        'ACCESS_CODE' => 'Chave de Acesso',
                        'ACCESS_LINK' => 'Link de Acesso',
                        'MICROSOFT_365', 'MICROSOFT', 'AZURE' => 'Microsoft 365',
                        'PASSWORD' => 'Senha',
                        '' => null,
                        default => (string) $state,
                    })
                    ->color('gray')
                    ->icon(fn (?string $state): string|BackedEnum|null => match (strtoupper((string) $state)) {
                        'ACCESS_CODE', 'ACCESS_LINK' => Heroicon::OutlinedKey,
                        'MICROSOFT_365', 'MICROSOFT', 'AZURE' => Heroicon::OutlinedCloud,
                        'PASSWORD' => Heroicon::OutlinedLockClosed,
                        default => Heroicon::OutlinedShieldCheck,
                    })
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('external_id')
                    ->label('ID Externo')
                    ->fontFamily(FontFamily::Mono)
                    ->limit(20)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->extraAttributes(['class' => 'text-xs text-slate-400'])
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Data de Criação')
                    ->dateTime('d/m/Y · H:i')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Última Atualização')
                    ->dateTime('d/m/Y · H:i')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'ACTIVE' => 'Ativo',
                        'INVITED' => 'Aguardando Cadastro',
                        'INACTIVE' => 'Inativo',
                        'BLOCKED' => 'Suspenso',
                    ]),
                SelectFilter::make('last_login_method')
                    ->label('Método de Autenticação')
                    ->options([
                        'ACCESS_CODE' => 'Chave de Acesso',
                        'ACCESS_LINK' => 'Link de Acesso',
                        'MICROSOFT_365' => 'Microsoft 365',
                        'PASSWORD' => 'Senha',
                    ]),
                TernaryFilter::make('has_logged_in')
                    ->label('Histórico de Acesso')
                    ->placeholder('Todos os usuários')
                    ->trueLabel('Já acessaram o portal')
                    ->falseLabel('Nunca acessaram')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('last_login_at'),
                        false: fn (Builder $query) => $query->whereNull('last_login_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar usuário')
                        ->icon(Heroicon::OutlinedPencilSquare),
                    Action::make('generate_token')
                        ->label('Gerar Chave de Acesso')
                        ->icon(Heroicon::OutlinedKey)
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
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do usuário'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Excluir selecionados'),
                ]),
            ])
            ->emptyStateHeading('Nenhum usuário do portal cadastrado')
            ->emptyStateDescription('Cadastre um usuário para disponibilizar acesso aos recursos do portal.')
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateActions([
                Action::make('create_portal_user')
                    ->label('Novo usuário')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->url(fn (): string => PortalUserResource::getUrl('create', panel: 'admin'))
                    ->visible(fn (): bool => auth()->user()?->can('nimbus.portal-users.create') ?? false),
            ])
            ->searchPlaceholder('Buscar por nome, e-mail ou ID externo...')
            ->searchOnBlur(false)
            ->striped();
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
