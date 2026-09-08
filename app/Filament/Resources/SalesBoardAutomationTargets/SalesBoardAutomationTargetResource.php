<?php

namespace App\Filament\Resources\SalesBoardAutomationTargets;

use App\Filament\Resources\SalesBoardAutomationTargets\Pages\ListSalesBoardAutomationTargets;
use App\Filament\Resources\SalesBoardAutomationTargets\Schemas\SalesBoardAutomationTargetInfolist;
use App\Filament\Resources\SalesBoardAutomationTargets\Tables\SalesBoardAutomationTargetsTable;
use App\Models\SalesBoardAutomationTarget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * A tela operacional da automação: o que está parado, desde quando e por quê.
 *
 * Somente leitura. A automação não recebe comando pela tela -- ela é conduzida
 * pelo scheduler, e um botão que forçasse a geração aqui teria de decidir se
 * ignora prontidão, data de vencimento ou janela de retry. Qualquer uma dessas
 * respostas transformaria a tela num caminho paralelo com regras próprias, que é
 * exatamente o que as fases anteriores evitaram.
 *
 * Não é um painel de filas: quem quer ver processo, worker e tentativa técnica
 * tem ferramenta própria. Aqui aparece o que o time do Quadro de Vendas precisa
 * para operar -- competência, empreendimento, motivo e próxima tentativa.
 *
 * As permissões são as do Quadro de Vendas, sem inventar nenhuma.
 */
class SalesBoardAutomationTargetResource extends Resource
{
    protected static ?string $model = SalesBoardAutomationTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Automação do Quadro';

    protected static ?string $modelLabel = 'Competência automatizada';

    protected static ?string $pluralModelLabel = 'Automação do Quadro de Vendas';

    protected static ?string $recordTitleAttribute = 'reference_month';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Emissões';

    protected static ?int $navigationSort = 15;

    public static function table(Table $table): Table
    {
        return SalesBoardAutomationTargetsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalesBoardAutomationTargetInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['construction', 'cycle']);
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
     * Estado operacional não se digita, não se corrige à mão e não se apaga: ele
     * é o registro do que a automação fez.
     */
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
            'index' => ListSalesBoardAutomationTargets::route('/'),
        ];
    }
}
