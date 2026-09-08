<?php

namespace App\Filament\Resources\SalesBoardRollouts;

use App\Filament\Resources\SalesBoardRollouts\Pages\ListSalesBoardRollouts;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Filament\Resources\SalesBoardRollouts\Tables\SalesBoardRolloutsTable;
use App\Models\Emission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Qual workflow produz os quadros mensais de cada Emissão.
 *
 * Superfície própria, e não uma aba de `EmissionResource`, porque o que ela
 * mostra é um processo e não um cadastro: modo atual, homologação em curso,
 * comparação com o legado por empreendimento, impactos revisados, responsáveis
 * definidos, portão e ativação. Espremer isso num formulário de Emissão faria a
 * decisão mais consequente do Quadro de Vendas virar mais um campo entre
 * cinquenta.
 *
 * O recurso é somente leitura: o modo não é um campo que se digita. Homologar,
 * aprovar, ativar e retornar são ações de domínio, cada uma com pré-condições
 * próprias.
 *
 * As permissões são as do Quadro de Vendas, sem inventar nenhuma.
 */
class SalesBoardRolloutResource extends Resource
{
    protected static ?string $model = Emission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $navigationLabel = 'Rollout do Quadro';

    protected static ?string $modelLabel = 'Rollout do Quadro de Vendas';

    protected static ?string $pluralModelLabel = 'Rollout do Quadro de Vendas';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Emissões';

    protected static ?int $navigationSort = 16;

    public static function table(Table $table): Table
    {
        return SalesBoardRolloutsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('constructions')
            ->with(['activeSalesBoardHomologation']);
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
     * Conduzir o rollout é escrita, e usa a mesma permissão de quem registra
     * posição no Quadro de Vendas.
     */
    public static function canManageRollout(): bool
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
            'index' => ListSalesBoardRollouts::route('/'),
            'manage' => ManageSalesBoardRollout::route('/{record}'),
        ];
    }
}
