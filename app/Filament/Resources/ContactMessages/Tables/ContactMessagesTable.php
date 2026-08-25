<?php

namespace App\Filament\Resources\ContactMessages\Tables;

use App\Enums\AccessPermission;
use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Models\ContactMessage;
use Carbon\Carbon;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContactMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchPlaceholder('Buscar por nome, assunto ou e-mail...')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhuma mensagem recebida')
            ->emptyStateDescription('As mensagens enviadas pelos canais de contato do site aparecerão aqui.')
            ->emptyStateIcon('heroicon-o-inbox')
            ->recordUrl(fn (ContactMessage $record): string => ContactMessageResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->weight(fn (ContactMessage $record): FontWeight => $record->status === ContactMessage::STATUS_NEW ? FontWeight::Bold : FontWeight::SemiBold)
                    ->icon(fn (ContactMessage $record): ?string => $record->status === ContactMessage::STATUS_NEW ? 'heroicon-m-envelope' : null)
                    ->iconColor('warning')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject')
                    ->label('Assunto')
                    ->limit(48)
                    ->tooltip(fn (ContactMessage $record): ?string => strlen((string) $record->subject) > 48 ? $record->subject : null)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ContactMessage::statusLabelFor($state))
                    ->color(fn (?string $state): string => ContactMessage::statusColorFor($state))
                    ->icon(fn (?string $state): string => match ($state) {
                        ContactMessage::STATUS_NEW => 'heroicon-m-sparkles',
                        ContactMessage::STATUS_IN_PROGRESS => 'heroicon-m-arrow-path',
                        ContactMessage::STATUS_DONE => 'heroicon-m-check-circle',
                        default => 'heroicon-m-envelope',
                    })
                    ->sortable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->icon('heroicon-m-at-symbol')
                    ->iconColor('gray')
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('E-mail copiado')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('Telefone')
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('attendedBy.name')
                    ->label('Atendido por')
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Recebida em')
                    ->formatStateUsing(function ($state): string {
                        if (! $state) {
                            return '—';
                        }
                        $date = Carbon::parse($state);
                        if ($date->isToday()) {
                            return 'Hoje · '.$date->format('H:i');
                        }
                        if ($date->isYesterday()) {
                            return 'Ontem · '.$date->format('H:i');
                        }

                        return $date->format('d/m/Y · H:i');
                    })
                    ->tooltip(fn (ContactMessage $record): ?string => $record->created_at?->format('d/m/Y H:i:s'))
                    ->fontFamily(FontFamily::Mono)
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ContactMessage::statusOptions()),

                SelectFilter::make('subject')
                    ->label('Assunto')
                    ->options(ContactMessage::SUBJECT_OPTIONS),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')->label('Recebida de'),
                        DatePicker::make('created_until')->label('Recebida até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ViewAction::make()
                    ->label('Visualizar')
                    ->icon('heroicon-m-eye')
                    ->color('gray'),

                EditAction::make()
                    ->label('Atender')
                    ->icon('heroicon-m-pencil-square')
                    ->color('primary')
                    ->visible(fn (): bool => auth()->user()?->can(AccessPermission::ContactMessagesUpdate->value) ?? false),
            ]);
    }
}
