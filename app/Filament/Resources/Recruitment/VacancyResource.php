<?php

namespace App\Filament\Resources\Recruitment;

use App\Enums\VacancyStatus;
use App\Filament\Resources\Recruitment\Infolists\VacancyInfolist;
use App\Filament\Resources\Recruitment\Pages\CreateVacancy;
use App\Filament\Resources\Recruitment\Pages\EditVacancy;
use App\Filament\Resources\Recruitment\Pages\ListVacancies;
use App\Filament\Resources\Recruitment\Pages\ViewVacancy;
use App\Filament\Resources\Recruitment\Schemas\VacancyForm;
use App\Filament\Resources\Recruitment\Tables\VacanciesTable;
use App\Models\Vacancy;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class VacancyResource extends Resource
{
    protected static ?string $model = Vacancy::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Vagas';

    protected static ?string $modelLabel = 'Vaga';

    protected static ?string $pluralModelLabel = 'Vagas';

    protected static string|\UnitEnum|null $navigationGroup = 'Site Institucional';

    protected static ?int $navigationSort = 20;

    public static function getNavigationBadge(): ?string
    {
        return (string) Vacancy::query()->where('status', VacancyStatus::Published->value)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Vacancy::query()->where('status', VacancyStatus::Published->value)->exists() ? 'success' : 'gray';
    }

    public static function form(Schema $schema): Schema
    {
        return VacancyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VacanciesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return VacancyInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVacancies::route('/'),
            'create' => CreateVacancy::route('/create'),
            'view' => ViewVacancy::route('/{record}'),
            'edit' => EditVacancy::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteKeyName(): string
    {
        return 'id';
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', Vacancy::class);
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('view', $record);
    }

    public static function canCreate(): bool
    {
        return Gate::allows('create', Vacancy::class);
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return Gate::allows('delete', $record);
    }
}
