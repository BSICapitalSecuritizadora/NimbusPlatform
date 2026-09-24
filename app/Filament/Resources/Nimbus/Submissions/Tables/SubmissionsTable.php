<?php

namespace App\Filament\Resources\Nimbus\Submissions\Tables;

use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Nimbus\Submission;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Submission $record): ?string => auth()->user()->can('view', $record) ? SubmissionResource::getUrl('view', ['record' => $record], panel: 'admin') : null)
            ->searchPlaceholder('Buscar por protocolo, solicitante, CNPJ ou razão social...')
            ->defaultSort('submitted_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhum envio recebido')
            ->emptyStateDescription('Os envios e solicitações recebidos aparecerão aqui para acompanhamento.')
            ->emptyStateIcon('heroicon-o-inbox-stack')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'portalUser',
            ]))
            ->columns([
                TextColumn::make('reference_code')
                    ->label('Protocolo')
                    ->copyable()
                    ->copyMessage('Protocolo copiado')
                    ->searchable()
                    ->fontFamily('mono')
                    ->weight('medium')
                    ->extraAttributes(['class' => 'whitespace-nowrap font-mono tabular-nums text-xs'])
                    ->toggleable(),

                TextColumn::make('portalUser.full_name')
                    ->label('Solicitante')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Submission $record): ?string => $record->portalUser?->email)
                    ->wrap(),

                TextColumn::make('company_cnpj')
                    ->label('CNPJ')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function (?string $state): string {
                        if (! filled($state)) {
                            return '—';
                        }
                        $digits = preg_replace('/\D/', '', $state);
                        if (strlen($digits) === 14) {
                            return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits);
                        }

                        return $state;
                    })
                    ->fontFamily('mono')
                    ->extraAttributes(['class' => 'whitespace-nowrap font-mono tabular-nums text-xs']),

                TextColumn::make('company_name')
                    ->label('Razão Social')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Submission $record): ?string => ($record->title && $record->title !== $record->company_name) ? $record->title : null),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Submission::statusLabelFor($state))
                    ->color(fn (?string $state): string => Submission::statusColorFor($state))
                    ->sortable(),

                TextColumn::make('submitted_at')
                    ->label('Data de Envio')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Submission $record): ?string => $record->submitted_at?->diffForHumans())
                    ->sortable()
                    ->extraAttributes(['class' => 'whitespace-nowrap tabular-nums text-xs']),
            ])
            ->filtersFormWidth(Width::Small)
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Submission::statusOptions()),
                SelectFilter::make('nimbus_portal_user_id')
                    ->label('Solicitante')
                    ->relationship('portalUser', 'full_name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
            ])
            ->actions([
                ViewAction::make()
                    ->label('Visualizar')
                    ->icon('heroicon-m-eye')
                    ->color('primary')
                    ->visible(fn (Submission $record): bool => auth()->user()->can('view', $record)),
            ]);
    }
}
