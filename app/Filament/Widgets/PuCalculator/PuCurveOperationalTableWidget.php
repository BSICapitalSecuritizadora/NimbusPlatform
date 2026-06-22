<?php

namespace App\Filament\Widgets\PuCalculator;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PuCurveOperationalTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Curvas por Emissão';

    public function table(Table $table): Table
    {
        $missingCdiIds = app(PuOperationalMonitorService::class)->missingCdiEmissionIds();
        $canExport = auth()->user()?->can('pu.curve.export') ?? false;

        return $table
            ->query(
                Emission::query()
                    ->whereHas('puParameter')
                    ->with([
                        'latestPuCurveVersion.generatedBy',
                    ]),
            )
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                TextColumn::make('name')
                    ->label('Emissão')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('latestPuCurveVersion.calculation_version')
                    ->label('Versão')
                    ->placeholder('—'),
                TextColumn::make('latestPuCurveVersion.status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof PuCurveStatus ? $state->label() : '—')
                    ->color(fn ($state): string => $state instanceof PuCurveStatus ? $state->color() : 'gray')
                    ->placeholder('—'),
                TextColumn::make('latestPuCurveVersion.generated_at')
                    ->label('Última geração')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('latestPuCurveVersion.validated_at')
                    ->label('Última validação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('latestPuCurveVersion.homologated_at')
                    ->label('Homologação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('latestPuCurveVersion.generatedBy.name')
                    ->label('Responsável')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('largest_pu_difference')
                    ->label('Maior dif. PU')
                    ->state(fn (Emission $record): string => $record->latestPuCurveVersion?->validation_summary['largest_pu_difference'] ?? '—'),
                TextColumn::make('cdi_coverage')
                    ->label('CDI')
                    ->badge()
                    ->state(fn (Emission $record): string => in_array($record->id, $missingCdiIds, true) ? 'Faltante' : 'OK')
                    ->color(fn (string $state): string => $state === 'Faltante' ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status da curva')
                    ->options(collect(PuCurveStatus::cases())->mapWithKeys(fn (PuCurveStatus $s): array => [$s->value => $s->label()])->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        $latestIds = EmissionPuCurveVersion::query()
                            ->selectRaw('MAX(id) as id')
                            ->groupBy('emission_id')
                            ->pluck('id');

                        $emissionIds = EmissionPuCurveVersion::query()
                            ->whereIn('id', $latestIds)
                            ->where('status', $value)
                            ->pluck('emission_id');

                        return $query->whereIn('id', $emissionIds);
                    }),
            ])
            ->recordUrl(fn (Emission $record): string => route('filament.admin.resources.emissions.pu-history', ['record' => $record]))
            ->actions([
                Action::make('timeline')
                    ->label('Timeline')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->url(fn (Emission $record): string => route('filament.admin.resources.emissions.pu-history', ['record' => $record])),
                Action::make('homologationPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (Emission $record): bool => $canExport && $record->latestPuCurveVersion !== null)
                    ->url(fn (Emission $record): string => route('admin.emissions.pu-homologation.pdf', [
                        'emission' => $record,
                        'version' => $record->latestPuCurveVersion,
                    ])),
                Action::make('reprocess')
                    ->label('Reprocessar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->url(fn (Emission $record): string => route('filament.admin.resources.emissions.edit', ['record' => $record])),
            ])
            ->emptyStateHeading('Nenhuma emissão com PU configurado')
            ->emptyStateDescription('Configure os parâmetros de PU em uma emissão para acompanhá-la aqui.');
    }
}
