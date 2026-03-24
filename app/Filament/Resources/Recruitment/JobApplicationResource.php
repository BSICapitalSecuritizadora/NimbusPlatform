<?php

namespace App\Filament\Resources\Recruitment;

use App\Filament\Resources\Recruitment\Pages\ListJobApplications;
use App\Filament\Resources\Recruitment\Pages\ViewJobApplication;
use App\Filament\Resources\Recruitment\Tables\JobApplicationsTable;
use App\Models\JobApplication;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class JobApplicationResource extends Resource
{
    protected static ?string $model = JobApplication::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Candidaturas';

    protected static ?string $modelLabel = 'Candidatura';

    protected static ?string $pluralModelLabel = 'Candidaturas';

    protected static string|\UnitEnum|null $navigationGroup = 'Recrutamento';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Detalhes da Candidatura')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')->disabled(),
                        TextInput::make('email')->disabled(),
                        TextInput::make('phone')->disabled(),
                        TextInput::make('linkedin_url')->disabled(),
                    ]),
                    Textarea::make('message')->disabled()->columnSpanFull(),
                    FileUpload::make('resume_path')
                        ->label('Currículo')
                        ->disabled()
                        ->columnSpanFull(),
                ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return JobApplicationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobApplications::route('/'),
            'view' => ViewJobApplication::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
