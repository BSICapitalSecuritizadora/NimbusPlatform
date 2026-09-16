<?php

namespace App\Filament\Resources\SalesBoardCycles;

use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBaselinesRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBuilderReviewsRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleLinesRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleMovementsRelationManager;
use App\Filament\Resources\SalesBoardCycles\Schemas\SalesBoardCycleInfolist;
use App\Filament\Resources\SalesBoardCycles\Tables\SalesBoardCyclesTable;
use App\Models\SalesBoardCycle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * O ciclo mensal, somente leitura.
 *
 * Recurso próprio, e não uma aba do Quadro de Vendas, porque as duas coisas
 * respondem perguntas diferentes: `SalesBoard` é a posição publicada, e o ciclo
 * é o que o Nimbus apurou -- com todas as versões pelas quais passou e a
 * situação de cada uma em relação à fonte viva.
 *
 * Não há criar, editar nem excluir. Um snapshot financeiro não se corrige
 * digitando por cima: congelar é ação de domínio, e refazer é recálculo, que
 * cria versão nova e exige motivo. Deixar um formulário de campos crus aqui
 * permitiria escrever à mão uma posição que ninguém apurou.
 *
 * As permissões são as do Quadro de Vendas, sem inventar nenhuma: quem enxerga a
 * posição enxerga o ciclo, e quem pode registrar posição pode congelar e
 * recalcular.
 */
class SalesBoardCycleResource extends Resource
{
    protected static ?string $model = SalesBoardCycle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static ?string $navigationLabel = 'Ciclos do Quadro';

    protected static ?string $modelLabel = 'Ciclo do Quadro de Vendas';

    protected static ?string $pluralModelLabel = 'Ciclos do Quadro de Vendas';

    protected static ?string $recordTitleAttribute = 'reference_month';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Emissões';

    protected static ?int $navigationSort = 11;

    public static function infolist(Schema $schema): Schema
    {
        return SalesBoardCycleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesBoardCyclesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SalesBoardCycleLinesRelationManager::class,
            SalesBoardCycleMovementsRelationManager::class,
            SalesBoardCycleBaselinesRelationManager::class,
            SalesBoardCycleBuilderReviewsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['emission', 'construction', 'currentBaseline']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('sales-boards.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('sales-boards.view') ?? false;
    }

    /**
     * Congelar e recalcular são as duas escritas que existem aqui, e ambas
     * acontecem por ação de domínio -- nunca por formulário.
     */
    public static function canGenerate(): bool
    {
        return auth()->user()?->can('sales-boards.create') ?? false;
    }

    public static function canRecalculate(): bool
    {
        return auth()->user()?->can('sales-boards.update') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Um ciclo não é apagável nem por quem pode apagar quadro publicado: ele é a
     * prova do que foi apurado, e prova que some sob demanda não é prova.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesBoardCycles::route('/'),
            'view' => ViewSalesBoardCycle::route('/{record}'),
            'builder-review' => BuilderReviewWorkspace::route('/{record}/validacao'),
            'management-review' => ManagementReviewWorkspace::route('/{record}/analise'),
        ];
    }
}
