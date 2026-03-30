<?php

namespace App\Filament\Resources\Recruitment\Tables;

use App\Models\JobApplication;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class JobApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => JobApplication::statusLabelFor($state))
                    ->color(fn (?string $state): string => JobApplication::statusColorFor($state))
                    ->sortable(),
                TextColumn::make('vacancy.title')
                    ->label('Vaga')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Telefone'),
                TextColumn::make('reviewedBy.name')
                    ->label('Última movimentação por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewed_at')
                    ->label('Última movimentação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(JobApplication::statusOptions()),
                SelectFilter::make('vacancy_id')
                    ->label('Vaga')
                    ->relationship('vacancy', 'title')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')->label('De'),
                        \Filament\Forms\Components\DatePicker::make('created_until')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                EditAction::make(),
                ViewAction::make(),
                Action::make('download_resume')
                    ->label('Currículo')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (JobApplication $record): string => Storage::url($record->resume_path))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
