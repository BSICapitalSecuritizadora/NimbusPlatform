<?php

namespace App\Filament\Resources\Negotiations;

use App\Filament\Resources\Negotiations\Pages\CreateNegotiation;
use App\Filament\Resources\Negotiations\Pages\EditNegotiation;
use App\Filament\Resources\Negotiations\Pages\ListNegotiations;
use App\Filament\Resources\Negotiations\Pages\ViewNegotiation;
use App\Filament\Resources\Negotiations\Schemas\NegotiationForm;
use App\Filament\Resources\Negotiations\Schemas\NegotiationInfolist;
use App\Filament\Resources\Negotiations\Tables\NegotiationsTable;
use App\Models\Emission;
use App\Models\Negotiation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class NegotiationResource extends Resource
{
    protected static ?string $model = Negotiation::class;

    public static function getNavigationBadge(): ?string
    {
        $count = Negotiation::query()->whereHas('emission', fn ($q) => $q->where('negotiations_source', Emission::NEGOTIATIONS_SOURCE_LEGACY))->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Negociações';

    protected static ?string $modelLabel = 'Negociação';

    protected static ?string $pluralModelLabel = 'Negociações';

    protected static ?string $recordTitleAttribute = 'reference_month';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Emissões';

    protected static ?int $navigationSort = 12;

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof Negotiation) {
            return null;
        }

        $formattedMonth = $record->formatted_reference_month ?: Negotiation::formatReferenceMonthForDisplay($record->reference_month);
        $emissionName = $record->emission?->name;

        if (filled($emissionName) && filled($formattedMonth)) {
            return "{$emissionName} · {$formattedMonth}";
        }

        return $formattedMonth ?: null;
    }

    public static function form(Schema $schema): Schema
    {
        return NegotiationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return NegotiationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NegotiationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'emission',
            'construction',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('negotiations.view') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! (auth()->user()?->can('negotiations.create') ?? false)) {
            return false;
        }

        // Guard contracts mode: if the current request targets a contracts-mode emission, deny creation.
        // Checks both table filter context (list page) and form payload (create page).
        $candidateEmissionId = request()->input('data.emission_id')
            ?? request()->input('emission_id')
            ?? request()->input('tableFilters.emission_id.value')
            ?? request()->input('tableFilters.emission_id');

        if (is_array($candidateEmissionId) && array_key_exists('value', $candidateEmissionId)) {
            $candidateEmissionId = $candidateEmissionId['value'];
        }

        if (filled($candidateEmissionId)) {
            $emission = Emission::query()->find($candidateEmissionId);
            if ($emission && $emission->usesContractNegotiations()) {
                return false;
            }
        }

        // If every emission is contracts-mode, no manual negotiations can be created at all.
        $hasContractsMode = Emission::query()->where('negotiations_source', Emission::NEGOTIATIONS_SOURCE_CONTRACTS)->exists();
        $hasLegacy = Emission::query()
            ->where(function (Builder $q): void {
                $q->where('negotiations_source', Emission::NEGOTIATIONS_SOURCE_LEGACY)
                    ->orWhereNull('negotiations_source');
            })->exists();

        if ($hasContractsMode && ! $hasLegacy) {
            return false;
        }

        return true;
    }

    public static function canEdit(Model $record): bool
    {
        if (! (auth()->user()?->can('negotiations.update') ?? false)) {
            return false;
        }

        if ($record instanceof Negotiation) {
            $emission = $record->emission ?? Emission::find($record->emission_id);
            if ($emission && $emission->usesContractNegotiations()) {
                return false;
            }
        }

        return true;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('negotiations.view') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('negotiations.delete') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNegotiations::route('/'),
            'create' => CreateNegotiation::route('/create'),
            'view' => ViewNegotiation::route('/{record}'),
            'edit' => EditNegotiation::route('/{record}/edit'),
        ];
    }
}
