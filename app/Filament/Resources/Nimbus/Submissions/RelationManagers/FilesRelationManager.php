<?php

namespace App\Filament\Resources\Nimbus\Submissions\RelationManagers;

use App\Models\Nimbus\SubmissionFile;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Arquivos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('original_name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('document_type_label')
            ->columns([
                TextColumn::make('document_type_label')
                    ->label('Documento')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $normalizedSearch = mb_strtolower($search);
                        $matchingTypes = collect(SubmissionFile::documentTypeLabels())
                            ->filter(fn (string $label, string $type): bool => str_contains(mb_strtolower($label), $normalizedSearch) || str_contains(mb_strtolower($type), $normalizedSearch))
                            ->keys()
                            ->all();

                        return $query->where(function (Builder $query) use ($matchingTypes, $search): void {
                            $query->where('original_name', 'like', "%{$search}%");

                            if ($matchingTypes !== []) {
                                $query->orWhereIn('document_type', $matchingTypes);
                            }
                        });
                    }),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
                AttachAction::make(),
            ])
            ->recordActions([
                Action::make('visualizar')
                    ->label('Visualizar')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (SubmissionFile $record): string => route('admin.nimbus.submissions.files.preview', $record))
                    ->openUrlInNewTab(),
                Action::make('baixar')
                    ->label('Baixar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (SubmissionFile $record): string => route('admin.nimbus.submissions.files.download', $record)),
                EditAction::make(),
                DetachAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
