<?php

namespace App\Filament\Resources\ResponsibilityDelegations\Tables;

use App\Enums\MeasurementResponsibility;
use App\Models\ResponsibilityDelegation;
use App\Services\ResponsibilityDelegationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ResponsibilityDelegationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('delegator.name')
                    ->label('Delegante')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('delegate.name')
                    ->label('Delegado')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('scope_type')
                    ->label('Escopo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ResponsibilityDelegation::SCOPE_OPTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'global' => 'primary',
                        'operation' => 'info',
                        'stage' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('scopeOperation.code')
                    ->label('Operação')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('scope_stage')
                    ->label('Etapa')
                    ->formatStateUsing(fn (?int $state): string => $state ? (ResponsibilityDelegation::STAGE_OPTIONS[$state] ?? (string) $state) : '—')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('scope_responsibility_label')
                    ->label('Responsabilidade')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('starts_at')
                    ->label('Início')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('Término')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ResponsibilityDelegation $record): string => app(ResponsibilityDelegationService::class)->effectiveStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'scheduled' => 'info',
                        'expired' => 'warning',
                        'revoked' => 'danger',
                        'ineffective' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Ativa',
                        'scheduled' => 'Agendada',
                        'expired' => 'Expirada',
                        'revoked' => 'Revogada',
                        'ineffective' => 'Ineficaz',
                        default => $state,
                    }),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(40)
                    ->tooltip(fn (ResponsibilityDelegation $record): string => $record->reason)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Ativa',
                        'scheduled' => 'Agendada',
                        'expired' => 'Expirada',
                        'revoked' => 'Revogada',
                    ])
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;
                        if (blank($value)) {
                            return $query;
                        }

                        $now = now();

                        return match ($value) {
                            'active' => $query->whereNull('revoked_at')->where('starts_at', '<=', $now)->where('ends_at', '>=', $now),
                            'scheduled' => $query->whereNull('revoked_at')->where('starts_at', '>', $now),
                            'expired' => $query->whereNull('revoked_at')->where('ends_at', '<', $now),
                            'revoked' => $query->whereNotNull('revoked_at'),
                            default => $query,
                        };
                    }),

                SelectFilter::make('scope_type')
                    ->label('Escopo')
                    ->options(ResponsibilityDelegation::SCOPE_OPTIONS),

                SelectFilter::make('delegator_user_id')
                    ->label('Delegante')
                    ->relationship('delegator', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('delegate_user_id')
                    ->label('Delegado')
                    ->relationship('delegate', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('scope_responsibility')
                    ->label('Responsabilidade')
                    ->options(MeasurementResponsibility::options()),

                Filter::make('period')
                    ->label('Período')
                    ->form([
                        DatePicker::make('from')->label('Vigente a partir de')->native(false),
                        DatePicker::make('until')->label('Vigente até')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('ends_at', '>=', $date),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('starts_at', '<=', $date),
                        )),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('revoke')
                    ->label('Revogar')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('revocation_reason')
                            ->label('Motivo da revogação')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->modalHeading('Revogar delegação')
                    ->modalDescription('A revogação é imediata e não pode ser desfeita. A autoridade delegada deixará de valer na hora.')
                    ->visible(fn (ResponsibilityDelegation $record): bool => $record->status === 'active' || $record->status === 'scheduled')
                    ->authorize(fn (ResponsibilityDelegation $record): bool => Auth::user()?->can('revoke', $record) ?? false)
                    ->action(function (ResponsibilityDelegation $record, array $data): void {
                        app(ResponsibilityDelegationService::class)->revokeDelegation(
                            $record,
                            Auth::user(),
                            $data['revocation_reason'],
                        );
                        Notification::make()
                            ->title('Delegação revogada')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nenhuma delegação encontrada')
            ->emptyStateDescription('Crie a primeira delegação para cobrir ausências temporárias sem alterar a responsabilidade da operação.')
            ->emptyStateIcon('heroicon-o-user-group');
    }
}
