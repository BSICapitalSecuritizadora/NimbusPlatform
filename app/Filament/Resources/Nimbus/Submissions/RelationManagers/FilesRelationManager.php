<?php

namespace App\Filament\Resources\Nimbus\Submissions\RelationManagers;

use App\Models\Nimbus\SubmissionFile;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
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
                TextColumn::make('original_name')
                    ->label('Arquivo')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('origin')
                    ->label('Origem')
                    ->formatStateUsing(fn (?string $state): string => $state === 'ADMIN' ? 'Equipe interna' : 'Portal')
                    ->badge(),
                TextColumn::make('visible_to_user')
                    ->label('Visibilidade')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Portal' : 'Interno')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('uploaded_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                Action::make('visualizar')
                    ->label('Visualizar')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (SubmissionFile $record): bool => auth()->user()->can('downloadFile', [$record->submission, $record]))
                    ->url(fn (SubmissionFile $record): string => route('admin.nimbus.submissions.files.preview', $record))
                    ->openUrlInNewTab(),
                Action::make('baixar')
                    ->label('Baixar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (SubmissionFile $record): bool => auth()->user()->can('downloadFile', [$record->submission, $record]))
                    ->url(fn (SubmissionFile $record): string => route('admin.nimbus.submissions.files.download', $record)),
                EditAction::make()
                    ->visible(fn (SubmissionFile $record): bool => auth()->user()->can('update', $record->submission)),
                DeleteAction::make()
                    ->visible(fn (SubmissionFile $record): bool => auth()->user()->can('update', $record->submission)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (RelationManager $livewire): bool => auth()->user()->can('update', $livewire->getOwnerRecord())),
                ]),
            ]);
    }
}
