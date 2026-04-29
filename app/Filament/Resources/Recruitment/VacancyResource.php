<?php

namespace App\Filament\Resources\Recruitment;

use App\Filament\Resources\Recruitment\Pages\CreateVacancy;
use App\Filament\Resources\Recruitment\Pages\EditVacancy;
use App\Filament\Resources\Recruitment\Pages\ListVacancies;
use App\Filament\Resources\Recruitment\Schemas\VacancyForm;
use App\Filament\Resources\Recruitment\Tables\VacanciesTable;
use App\Models\Vacancy;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VacancyResource extends Resource
{
    protected static ?string $model = Vacancy::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Vagas';

    protected static ?string $modelLabel = 'Vaga';

    protected static ?string $pluralModelLabel = 'Vagas';

    protected static string|\UnitEnum|null $navigationGroup = 'Recrutamento';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        return (string) Vacancy::query()->where('is_active', true)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Vacancy::query()->where('is_active', true)->exists() ? 'success' : 'gray';
    }

    public static function form(Schema $schema): Schema
    {
        return VacancyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VacanciesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVacancies::route('/'),
            'create' => CreateVacancy::route('/create'),
            'edit' => EditVacancy::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('recruitment.vacancies.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('recruitment.vacancies.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('recruitment.vacancies.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('recruitment.vacancies.delete') ?? false;
    }
}
