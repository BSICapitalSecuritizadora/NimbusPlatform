<?php

namespace App\Filament\Widgets\Nimbus\Widgets;

use App\Models\Nimbus\Submission;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestSubmissions extends BaseWidget
{
    protected static ?string $heading = 'Últimas Submissões Recebidas no Portal';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Submission::query()->latest('submitted_at')->take(5))
            ->columns([
                Tables\Columns\TextColumn::make('reference_code')->label('Código')->searchable(),
                Tables\Columns\TextColumn::make('title')->label('Título')->searchable(),
                Tables\Columns\TextColumn::make('portalUser.full_name')->label('Emissor')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'UNDER_REVIEW' => 'info',
                        'COMPLETED' => 'success',
                        'REJECTED' => 'danger',
                        default => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('submitted_at')->label('Data')->dateTime('d/m/Y H:i'),
            ])
            ->paginated(false);
    }
}
