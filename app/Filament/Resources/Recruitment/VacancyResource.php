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

class VacancyResource extends Resource
{
    protected static ?string $model = Vacancy::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Vagas';

    protected static ?string $modelLabel = 'Vaga';

    protected static ?string $pluralModelLabel = 'Vagas';

    protected static string|\UnitEnum|null $navigationGroup = 'Recrutamento';

    protected static ?int $navigationSort = 10;

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
}
