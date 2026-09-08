<?php

namespace App\Filament\Resources\ConstructionUnits;

use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\Filament\Resources\ConstructionUnits\Pages\CreateConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Pages\EditConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitExchangesRelationManager;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitValuesRelationManager;
use App\Filament\Resources\ConstructionUnits\Schemas\ConstructionUnitForm;
use App\Filament\Resources\ConstructionUnits\Tables\ConstructionUnitsTable;
use App\Models\ConstructionUnit;
use App\Services\SalesBoards\UnitValueResolver;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ConstructionUnitResource extends Resource
{
    protected static ?string $model = ConstructionUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Unidades';

    protected static ?string $modelLabel = 'Unidade';

    protected static ?string $pluralModelLabel = 'Unidades';

    protected static ?string $recordTitleAttribute = 'unit';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Obras';

    protected static ?int $navigationSort = 23;

    public static function form(Schema $schema): Schema
    {
        return ConstructionUnitForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Unidade')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('construction.emission.name')
                        ->label('Emissão')
                        ->columnSpanFull(),
                    TextEntry::make('construction.development_name')
                        ->label('Empreendimento')
                        ->columnSpanFull(),
                    TextEntry::make('block')->label('Bloco'),
                    TextEntry::make('unit')->label('Unidade')->weight('bold'),
                ]),

            Section::make('Valores')
                ->description('Referência inicial da unidade e o valor que vale hoje.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('base_value')
                        ->label('Valor base')
                        ->money('BRL')
                        ->placeholder('Não informado'),

                    TextEntry::make('base_value_reference_date')
                        ->label('Data de referência do valor base')
                        ->date('d/m/Y')
                        ->placeholder('Não informada'),

                    TextEntry::make('current_value')
                        ->label('Valor vigente')
                        ->weight('bold')
                        ->state(fn (ConstructionUnit $record): string => self::currentValue($record)->formattedValue() === null
                            ? 'Sem valor conhecido'
                            : 'R$ '.self::currentValue($record)->formattedValue()),

                    TextEntry::make('current_value_effective_from')
                        ->label('Vigente desde')
                        ->state(function (ConstructionUnit $record): string {
                            $resolved = self::currentValue($record);

                            if ($resolved->isAbsent()) {
                                return '—';
                            }

                            return sprintf(
                                '%s (%s)',
                                $resolved->effectiveFrom?->format('d/m/Y') ?? '—',
                                $resolved->source->label(),
                            );
                        }),
                ]),
        ]);
    }

    /**
     * Valor da unidade hoje, resolvido pela mesma regra que a Fase B vai usar:
     * o último histórico até hoje, ou o valor base se ele já estiver valendo.
     *
     * Deliberadamente sem memoização estática. Um cache em propriedade `static`
     * sobrevive à requisição num processo de vida longa -- e à troca de registro
     * entre dois testes -- e passaria a responder o valor de outra unidade. A
     * consulta é uma só por chamada, com a unidade já carregada.
     */
    private static function currentValue(ConstructionUnit $record): ResolvedUnitValue
    {
        return app(UnitValueResolver::class)->forUnit($record, CarbonImmutable::now());
    }

    public static function getRelations(): array
    {
        return [
            ConstructionUnitValuesRelationManager::class,
            ConstructionUnitExchangesRelationManager::class,
        ];
    }

    public static function table(Table $table): Table
    {
        return ConstructionUnitsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'construction.emission',
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['unit', 'block', 'construction.development_name', 'construction.emission.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->display_name;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('constructions.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('constructions.create') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('constructions.view') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('constructions.update') ?? false;
    }

    /**
     * A unit that already carries contracts is commercial history: removing it
     * would orphan the sales made on it, so the database refuses and the action
     * is not offered.
     */
    public static function canDelete(Model $record): bool
    {
        if (! (auth()->user()?->can('constructions.delete') ?? false)) {
            return false;
        }

        if (! ($record instanceof ConstructionUnit)) {
            return true;
        }

        /**
         * O histórico de valores é protegido pela FK exatamente como os
         * contratos: apagar a unidade destruiria a resposta para quanto ela
         * valia em cada data. A guarda existe para o usuário ver o motivo em vez
         * de um erro de constraint.
         */
        return ! $record->contracts()->withTrashed()->exists()
            && ! $record->valueHistories()->exists()
            && ! $record->exchanges()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConstructionUnits::route('/'),
            'create' => CreateConstructionUnit::route('/create'),
            'view' => ViewConstructionUnit::route('/{record}'),
            'edit' => EditConstructionUnit::route('/{record}/edit'),
        ];
    }
}
