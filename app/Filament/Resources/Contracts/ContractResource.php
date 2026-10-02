<?php

namespace App\Filament\Resources\Contracts;

use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractInstallmentsRelationManager;
use App\Filament\Resources\Contracts\Schemas\ContractForm;
use App\Filament\Resources\Contracts\Schemas\ContractInfolist;
use App\Filament\Resources\Contracts\Tables\ContractsTable;
use App\Filament\Support\AuthorizesThroughModelPolicy;
use App\Models\Contract;
use App\Policies\ContractPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Autorizado pela {@see ContractPolicy}: as permissões
 * `contracts.*` e as guardas do contrato já lido pelo Quadro de Vendas vivem lá.
 */
class ContractResource extends Resource
{
    use AuthorizesThroughModelPolicy;

    /**
     * A exclusão não é o distrato. A derivação ignora contratos excluídos, então
     * excluir um contrato vendido o faz nunca ter existido em todas as
     * competências; o distrato é um fato datado, que devolve a unidade ao
     * estoque a partir da data dele.
     */
    public const DELETE_MODAL_DESCRIPTION = 'Use a exclusão apenas para um contrato registrado por engano. '
        .'Para um distrato, edite o contrato, mude o status para Distratado e informe a Data do Distrato: '
        .'a venda continua no histórico e a unidade volta ao estoque a partir dessa data.';

    protected static ?string $model = Contract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Contratos';

    protected static ?string $modelLabel = 'Contrato';

    protected static ?string $pluralModelLabel = 'Contratos';

    protected static ?string $recordTitleAttribute = 'code';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return ContractForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContractInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractsTable::configure($table);
    }

    /**
     * Every column of the listing reads through a relation, so they are all
     * loaded up front: without this the table costs four queries per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with([
                'clients',
                'constructionUnit',
                'construction.emission',
            ]);
    }

    /**
     * Importar concilia a carteira -- cadastra e atualiza --, e por isso é
     * respondido pela ability `import` da policy, que exige criar e editar.
     */
    public static function canImport(): bool
    {
        return static::getAuthorizationResponse('import')->allowed();
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'clients.name', 'clients.document', 'constructionUnit.unit', 'construction.development_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->code;
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Compradores' => $record->buyersLabel(),
            'Unidade' => $record->constructionUnit?->display_name ?? '—',
        ];
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            ContractInstallmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContracts::route('/'),
            'create' => CreateContract::route('/create'),
            'view' => ViewContract::route('/{record}'),
            'edit' => EditContract::route('/{record}/edit'),
        ];
    }
}
