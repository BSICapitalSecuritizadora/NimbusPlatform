<?php

namespace App\Filament\Resources\BankApplications\RelationManagers;

use App\Filament\Resources\FundAllocations\FundAllocationResource;
use App\Filament\Resources\FundAllocations\Tables\FundAllocationsTable;
use App\Models\BankApplication;
use App\Models\FundAllocationPeriod;
use App\Services\Funds\FundAllocationService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BankApplicationAllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocationPeriods';

    protected static ?string $title = 'Alocações por Competência';

    protected static ?string $modelLabel = 'Alocação por competência';

    protected static ?string $pluralModelLabel = 'Alocações por competência';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('competence')
            ->recordUrl(fn (FundAllocationPeriod $record): ?string => FundAllocationResource::getUrl('view', ['record' => $record]))
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['openRevision', 'effectiveRevision'])
                ->orderByDesc('competence'))
            ->columns([
                TextColumn::make('competence')
                    ->label('Competência')
                    ->state(fn (FundAllocationPeriod $record): string => $record->competence?->format('m/Y') ?? '—')
                    ->weight('semibold'),

                TextColumn::make('allocation_status')
                    ->label('Situação')
                    ->state(fn (FundAllocationPeriod $record): string => FundAllocationsTable::statusLabel($record))
                    ->badge()
                    ->color(fn (FundAllocationPeriod $record): string => FundAllocationsTable::statusColor($record)),

                TextColumn::make('allocated_amount')
                    ->label('Alocado')
                    ->state(function (FundAllocationPeriod $record): string {
                        $revision = FundAllocationsTable::displayRevision($record);

                        return $revision !== null ? (string) $revision->total_amount : '—';
                    })
                    ->money('BRL')
                    ->alignment('end')
                    ->fontFamily('mono'),

                TextColumn::make('unallocated_amount')
                    ->label('Não alocado')
                    ->state(function (FundAllocationPeriod $record): string {
                        $revision = FundAllocationsTable::displayRevision($record);

                        return $revision !== null ? (string) $revision->unallocated_amount : '—';
                    })
                    ->money('BRL')
                    ->alignment('end')
                    ->fontFamily('mono'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhuma alocação para esta aplicação')
            ->emptyStateDescription('As competências alocadas aparecem aqui com a situação de cada revisão.')
            ->emptyStateIcon('heroicon-o-scale');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof BankApplication) {
            return false;
        }

        return auth()->user()?->can(FundAllocationService::PERMISSION_VIEW) ?? false;
    }
}
