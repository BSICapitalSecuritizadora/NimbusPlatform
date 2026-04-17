<?php

namespace App\Filament\NimbusWidgets;

use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use App\Models\Nimbus\Submission;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class NimbusRecentSubmissions extends BaseWidget
{
    protected static ?string $heading = 'Envios recentes';

    // Span 2 of 3 columns
    protected int|string|array $columnSpan = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Submission::query()
                    ->latest('submitted_at')
                    ->take(6),
            )
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'portalUser',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('portalUser.full_name')
                    ->label('Solicitante')
                    ->searchable(),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Data de envio')
                    ->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Submission::statusLabelFor($state))
                    ->color(fn (?string $state): string => Submission::statusColorFor($state)),
            ])
            ->actions([
                Action::make('Ver detalhes')
                    ->icon('heroicon-m-chevron-right')
                    ->iconButton()
                    ->url(fn (Submission $record): string => SubmissionResource::getUrl('view', ['record' => $record], panel: 'admin')),
            ])
            ->paginated(false);
    }
}
