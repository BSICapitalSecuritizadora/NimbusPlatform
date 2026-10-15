<?php

namespace App\Filament\Resources\BankAccounts\RelationManagers;

use App\Filament\Resources\Funds\FundResource;
use App\Models\Fund;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BankAccountFundsRelationManager extends RelationManager
{
    protected static string $relationship = 'funds';

    protected static ?string $title = 'Fundos Vinculados';

    protected static ?string $modelLabel = 'Fundo vinculado';

    protected static ?string $pluralModelLabel = 'Fundos vinculados';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('account')
            ->recordUrl(fn (Fund $record): ?string => FundResource::canEdit($record)
                ? FundResource::getUrl('edit', ['record' => $record])
                : null)
            ->modifyQueryUsing(fn ($query) => $query->with(['emission', 'fundName', 'bankApplication']))
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Operação')
                    ->weight('semibold')
                    ->placeholder('—')
                    ->wrap()
                    ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? true),

                TextColumn::make('fundName.name')
                    ->label('Nome do fundo')
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('bankApplication.codigo_produto')
                    ->label('Aplicação bancária')
                    ->fontFamily('mono')
                    ->placeholder('Conta direta (aplicação pendente)'),

                TextColumn::make('balance')
                    ->label('Saldo do fundo')
                    ->money('BRL')
                    ->alignment('end')
                    ->fontFamily('mono')
                    ->placeholder('R$ 0,00')
                    ->tooltip('Saldo atribuído a este fundo. Não representa o saldo físico da conta.'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhum fundo vinculado diretamente a esta conta')
            ->emptyStateDescription('Os fundos vinculados a esta conta — diretamente ou por aplicação — aparecerão aqui.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('funds.view') ?? false;
    }
}
