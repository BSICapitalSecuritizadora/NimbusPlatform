<?php

namespace App\Filament\Resources\FundAllocations\RelationManagers;

use App\Filament\Resources\FundAllocations\Schemas\FundAllocationInfolist;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Services\Funds\FundAllocationService;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FundAllocationRevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Histórico de revisões';

    protected static ?string $modelLabel = 'Revisão';

    protected static ?string $pluralModelLabel = 'Revisões';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('revision_number')
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['snapshot', 'proposedBy', 'submittedBy', 'approvedBy'])
                ->orderByDesc('revision_number'))
            ->columns([
                TextColumn::make('revision_number')
                    ->label('Revisão')
                    ->state(fn (FundAllocationRevision $record): string => "#{$record->revision_number}")
                    ->fontFamily('mono')
                    ->weight('semibold'),

                TextColumn::make('status')
                    ->label('Situação')
                    ->state(fn (FundAllocationRevision $record): string => $record->statusLabel()
                        .($record->isCorrection() ? ' · correção' : ''))
                    ->badge()
                    ->color(fn (FundAllocationRevision $record): string => match ($record->status) {
                        FundAllocationRevision::STATUS_APPROVED => 'success',
                        FundAllocationRevision::STATUS_PENDING_REVIEW => 'warning',
                        FundAllocationRevision::STATUS_DRAFT => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('snapshot.version')
                    ->label('Ref.')
                    ->state(fn (FundAllocationRevision $record): string => $record->snapshot !== null ? "v{$record->snapshot->version}" : '—')
                    ->fontFamily('mono')
                    ->tooltip('Versão do saldo de referência vinculada a esta revisão.'),

                TextColumn::make('reference_amount')
                    ->label('Referência')
                    ->money('BRL')
                    ->alignment('end')
                    ->fontFamily('mono'),

                TextColumn::make('total_amount')
                    ->label('Alocado')
                    ->money('BRL')
                    ->alignment('end')
                    ->fontFamily('mono'),

                TextColumn::make('unallocated_amount')
                    ->label('Não alocado')
                    ->money('BRL')
                    ->alignment('end')
                    ->fontFamily('mono')
                    ->weight('medium'),

                TextColumn::make('proposedBy.name')
                    ->label('Proposta por')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('approvedBy.name')
                    ->label('Aprovada por')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('approved_at')
                    ->label('Aprovada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('correction_reason')
                    ->label('Motivo da correção')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('view_lines')
                    ->label('Ver linhas')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->modalHeading(fn (?FundAllocationRevision $record): string => $record instanceof FundAllocationRevision
                        ? "Revisão #{$record->revision_number} · {$record->statusLabel()}"
                        : 'Revisão')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->infolist(fn (?FundAllocationRevision $record): array => [
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->contained(false)
                            ->state(fn (): array => FundAllocationInfolist::lineRows($record))
                            ->schema(fn (): array => FundAllocationInfolist::lineSchema()),
                    ]),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhuma revisão para esta competência')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof FundAllocationPeriod) {
            return false;
        }

        return auth()->user()?->can(FundAllocationService::PERMISSION_VIEW) ?? false;
    }
}
