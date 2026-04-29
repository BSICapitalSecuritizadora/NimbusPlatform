<?php

namespace App\Filament\Resources\Recruitment;

use App\Filament\Resources\Recruitment\Pages\EditJobApplication;
use App\Filament\Resources\Recruitment\Pages\ListJobApplications;
use App\Filament\Resources\Recruitment\Pages\ViewJobApplication;
use App\Filament\Resources\Recruitment\Tables\JobApplicationsTable;
use App\Models\JobApplication;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class JobApplicationResource extends Resource
{
    protected static ?string $model = JobApplication::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Candidaturas';

    protected static ?string $modelLabel = 'Candidatura';

    protected static ?string $pluralModelLabel = 'Candidaturas';

    protected static string|\UnitEnum|null $navigationGroup = 'Recrutamento';

    protected static ?int $navigationSort = 20;

    public static function getNavigationBadge(): ?string
    {
        return (string) JobApplication::query()->where('status', JobApplication::STATUS_NEW)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return JobApplication::query()->where('status', JobApplication::STATUS_NEW)->exists() ? 'warning' : 'gray';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'vacancy',
                'reviewedBy',
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Triagem')
                ->schema([
                    Select::make('status')
                        ->label('Status')
                        ->options(JobApplication::statusOptions())
                        ->required()
                        ->native(false),
                    Placeholder::make('vacancy_title')
                        ->label('Vaga')
                        ->content(fn (?JobApplication $record): string => $record?->vacancy?->title ?? '—'),
                    Placeholder::make('submitted_at')
                        ->label('Recebida em')
                        ->content(fn (?JobApplication $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('reviewed_by')
                        ->label('Última movimentação por')
                        ->content(fn (?JobApplication $record): string => $record?->reviewedBy?->name ?? '—'),
                    Placeholder::make('reviewed_at_display')
                        ->label('Última movimentação em')
                        ->content(fn (?JobApplication $record): string => $record?->reviewed_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('resume_download')
                        ->label('Currículo')
                        ->content(fn (?JobApplication $record): HtmlString|string => $record?->resume_path
                            ? new HtmlString(
                                '<a href="'.e(Storage::url($record->resume_path)).'" target="_blank" class="text-primary-600 hover:underline">Abrir currículo ↗</a>'
                            )
                            : '—'),
                    Textarea::make('internal_notes')
                        ->label('Notas internas')
                        ->rows(6)
                        ->columnSpanFull(),
                    Textarea::make('message')
                        ->label('Mensagem enviada')
                        ->disabled()
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Dados do candidato')
                ->schema([
                    TextInput::make('name')->label('Nome')->disabled(),
                    TextInput::make('email')->label('E-mail')->disabled(),
                    TextInput::make('phone')->label('Telefone')->disabled(),
                    TextInput::make('linkedin_url')->label('LinkedIn')->disabled(),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Triagem')
                ->schema([
                    TextEntry::make('status')
                        ->label('Status')
                        ->formatStateUsing(fn (?string $state): string => JobApplication::statusLabelFor($state)),
                    TextEntry::make('vacancy.title')->label('Vaga'),
                    TextEntry::make('created_at')->label('Recebida em')->dateTime('d/m/Y H:i'),
                    TextEntry::make('reviewedBy.name')->label('Última movimentação por')->placeholder('—'),
                    TextEntry::make('reviewed_at')->label('Última movimentação em')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('internal_notes')->label('Notas internas')->placeholder('Sem notas')->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Dados do candidato')
                ->schema([
                    TextEntry::make('name')->label('Nome'),
                    TextEntry::make('email')->label('E-mail'),
                    TextEntry::make('phone')->label('Telefone'),
                    TextEntry::make('linkedin_url')->label('LinkedIn')->placeholder('—'),
                    TextEntry::make('message')->label('Mensagem')->placeholder('Sem mensagem')->columnSpanFull(),
                    TextEntry::make('resume_path')
                        ->label('Currículo')
                        ->formatStateUsing(fn (?string $state): string => $state ? basename($state) : '—'),
                ])
                ->columns(2),
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
            'edit' => EditJobApplication::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('recruitment.applications.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('recruitment.applications.view') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('recruitment.applications.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('recruitment.applications.delete') ?? false;
    }
}
