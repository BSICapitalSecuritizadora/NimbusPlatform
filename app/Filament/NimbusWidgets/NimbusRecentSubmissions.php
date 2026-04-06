<?php

namespace App\Filament\NimbusWidgets;

use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use App\Models\Nimbus\Submission;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class NimbusRecentSubmissions extends BaseWidget
{
    protected static ?string $heading = 'Envios Recentes';

    // Span 2 of 3 columns
    protected int|string|array $columnSpan = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(Submission::query()->latest('submitted_at')->take(6))
            ->columns([
                Tables\Columns\TextColumn::make('portalUser.full_name')
                    ->label('SOLICITANTE')
                    ->searchable(),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('DATA DE ENVIO')
                    ->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('status')
                    ->label('SITUAÇÃO')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'UNDER_REVIEW' => 'info',
                        'COMPLETED' => 'success',
                        'REJECTED' => 'danger',
                        default => 'secondary',
                    }),
            ])
            ->actions([
                Action::make('Ver Detalhes')
                    ->icon('heroicon-m-chevron-right')
                    ->iconButton()
                    ->url(fn (Submission $record): string => SubmissionResource::getUrl('view', ['record' => $record], panel: 'admin')),
            ])
            ->paginated(false);
    }
}
