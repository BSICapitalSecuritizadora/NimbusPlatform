<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes\Tables;

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use App\Models\Nimbus\NotificationOutbox;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationOutboxesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchPlaceholder('Buscar por destinatário, assunto ou classificação...')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhum envio encontrado'
                : 'Nenhum envio registrado')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Tente ajustar os filtros ou o termo pesquisado.'
                : 'Os envios processados pela plataforma aparecerão aqui para auditoria.')
            ->emptyStateIcon('heroicon-o-inbox-stack')
            ->emptyStateActions([
                Action::make('clear_table_filters')
                    ->label('Limpar filtros')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => static::hasActiveFiltersOrSearch($livewire))
                    ->action(function ($livewire): void {
                        $livewire->resetTableFilters();
                        $livewire->resetTableSearch();
                    }),
            ])
            ->columns([
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (NotificationOutbox $record): string => $record->status_color)
                    ->icon(fn (NotificationOutbox $record): ?string => match (strtoupper((string) $record->status)) {
                        'PENDING' => 'heroicon-m-clock',
                        'SENDING' => 'heroicon-m-arrow-path',
                        'SENT' => 'heroicon-m-check-circle',
                        'FAILED' => 'heroicon-m-x-circle',
                        'CANCELLED' => 'heroicon-m-no-symbol',
                        default => null,
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('status', $direction)),

                TextColumn::make('type_label')
                    ->label('Classificação')
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-m-tag')
                    ->searchable(['type'])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('type', $direction)),

                TextColumn::make('recipient_email')
                    ->label('Destinatário')
                    ->state(fn (NotificationOutbox $record): string => $record->recipient_name ?: $record->recipient_email)
                    ->description(fn (NotificationOutbox $record): ?string => $record->recipient_name ? $record->recipient_email : null)
                    ->tooltip(fn (NotificationOutbox $record): string => $record->recipient_name ? "{$record->recipient_name} <{$record->recipient_email}>" : $record->recipient_email)
                    ->searchable(['recipient_email', 'recipient_name'])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('recipient_email', $direction)),

                TextColumn::make('subject')
                    ->label('Assunto')
                    ->weight('semibold')
                    ->wrap()
                    ->limit(55)
                    ->tooltip(fn (NotificationOutbox $record): string => $record->subject)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('attempts')
                    ->label('Tentativas')
                    ->state(fn (NotificationOutbox $record): string => $record->attempts === 1 ? '1 tentativa' : "{$record->attempts} de {$record->max_attempts}")
                    ->badge()
                    ->color(fn (NotificationOutbox $record): string => match (true) {
                        strtoupper((string) $record->status) === 'FAILED' => 'danger',
                        $record->attempts > 1 => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (NotificationOutbox $record): string => "{$record->attempts} de {$record->max_attempts} tentativas permitidas")
                    ->extraAttributes(['class' => 'font-mono tabular-nums text-xs whitespace-nowrap'])
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Data de Criação')
                    ->dateTime('d/m/Y · H:i:s')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-400'])
                    ->sortable(),

                TextColumn::make('sent_at')
                    ->label('Data de Envio')
                    ->dateTime('d/m/Y · H:i:s')
                    ->placeholder('—')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-400'])
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('correlation_id')
                    ->label('ID de Correlação')
                    ->placeholder('—')
                    ->limit(20)
                    ->tooltip(fn (NotificationOutbox $record): ?string => $record->correlation_id)
                    ->extraAttributes(['class' => 'font-mono text-xs'])
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Medium)
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'PENDING' => 'Aguardando',
                        'SENDING' => 'Enviando',
                        'SENT' => 'Concluído',
                        'FAILED' => 'Falhou',
                        'CANCELLED' => 'Cancelado',
                    ]),

                SelectFilter::make('type')
                    ->label('Classificação')
                    ->options([
                        'token_created' => 'Geração de Chave de Acesso',
                        'password_reset' => 'Redefinição de Senha',
                        'welcome_email' => 'Boas-vindas',
                        'submission_received' => 'Protocolo Recebido',
                        'user_precreated' => 'Pré-cadastro de Usuário',
                        'new_announcement' => 'Novo Comunicado',
                        'new_general_document' => 'Documento Publicado',
                    ]),

                TernaryFilter::make('has_error')
                    ->label('Ocorrência de Falha')
                    ->placeholder('Todos os envios')
                    ->trueLabel('Apenas com erro')
                    ->falseLabel('Sem erros registrados')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('last_error'),
                        false: fn (Builder $query): Builder => $query->whereNull('last_error'),
                    ),

                Filter::make('periodo')
                    ->form([
                        DatePicker::make('created_from')->label('Criado a partir de'),
                        DatePicker::make('created_until')->label('Criado até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->modalHeading('Auditoria do Envio')
                        ->modalWidth(Width::FourExtraLarge)
                        ->slideOver(),

                    NotificationOutboxResource::getCancelAction(),
                    NotificationOutboxResource::getReprocessAction(),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do envio'),
            ]);
    }

    /**
     * Verifica se há busca ou filtros ativos na tabela.
     */
    protected static function hasActiveFiltersOrSearch($livewire): bool
    {
        if (filled($livewire->tableSearch ?? null)) {
            return true;
        }

        $hasValue = function (mixed $value) use (&$hasValue): bool {
            if (is_array($value)) {
                return collect($value)->contains(fn (mixed $item): bool => $hasValue($item));
            }

            return filled($value);
        };

        return collect($livewire->tableFilters ?? [])->contains(fn (mixed $state): bool => $hasValue($state));
    }
}
