<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Filament\Exports\ObligationExporter;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ObligationOperationalTableWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Obrigações que Requerem Atenção';

    public function table(Table $table): Table
    {
        $data = app(ObligationDashboardData::class);
        $user = auth()->user();
        $canViewEvidence = (bool) $user?->can(AccessPermission::ObligationsViewEvidence->value);
        $canOpenEmission = (bool) $user?->can(AccessPermission::EmissionsView->value);
        $canExport = (bool) $user?->can(AccessPermission::ObligationsView->value)
            && (bool) $user?->can(AccessPermission::ObligationsViewDashboard->value)
            && (bool) $user?->can(AccessPermission::ObligationsExport->value);
        $pageFilters = $data->sanitizeFilters($this->pageFilters, $canViewEvidence);

        $emissionUrl = static fn (Obligation $record): ?string => $canOpenEmission
            ? EmissionResource::getUrl('edit', ['record' => $record->emission_id])
            : null;

        $obligationUrl = static fn (Obligation $record): ?string => $canOpenEmission
            ? EmissionResource::getUrl('edit', [
                'record' => $record->emission_id,
                'relation' => ObligationsRelationManager::class,
            ])
            : null;

        $columns = [
            TextColumn::make('emission.name')
                ->label('Emissão')
                ->searchable()
                ->wrap()
                ->url($emissionUrl),
            TextColumn::make('title')
                ->label('Obrigação')
                ->searchable()
                ->wrap()
                ->limit(70)
                ->url($obligationUrl),
            TextColumn::make('operational_focus')
                ->label('Foco Operacional')
                ->state(fn (Obligation $record): string => $data->operationalFocusLabelFor($record))
                ->badge()
                ->color(fn (Obligation $record): string => $data->operationalFocusColorFor($record))
                ->wrap(),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => Obligation::STATUS_OPTIONS[$state] ?? (string) $state)
                ->color(fn (?string $state): string => match ($state) {
                    'em_dia', 'concluida' => 'success',
                    'a_vencer' => 'info',
                    'vencida' => 'danger',
                    'em_analise' => 'warning',
                    default => 'gray',
                }),
            TextColumn::make('due_date')
                ->label('Vencimento')
                ->date('d/m/Y')
                ->placeholder('Sem data')
                ->sortable(),
            TextColumn::make('aging')
                ->label('Aging')
                ->state(fn (Obligation $record): ?string => $data->agingLabelFor($record))
                ->placeholder('—')
                ->badge()
                ->color(fn (?string $state): string => match ($state) {
                    'Mais de 30 dias', '16 a 30 dias' => 'danger',
                    '8 a 15 dias', '1 a 7 dias' => 'warning',
                    default => 'gray',
                }),
            TextColumn::make('responsibleUser.name')
                ->label('Responsável')
                ->placeholder('Sem responsável')
                ->toggleable(),
            TextColumn::make('responsible_area')
                ->label('Área')
                ->placeholder('Sem área')
                ->toggleable(),
            TextColumn::make('priority')
                ->label('Prioridade')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => Obligation::PRIORITY_OPTIONS[$state] ?? (string) $state)
                ->color(fn (?string $state): string => match ($state) {
                    'critical' => 'danger',
                    'high' => 'warning',
                    'medium' => 'info',
                    default => 'gray',
                }),
        ];

        if ($canViewEvidence) {
            $columns[] = TextColumn::make('document_status')
                ->label('Status das Evidências')
                ->state(fn (Obligation $record): string => $data->documentStatusFor($record))
                ->badge()
                ->color(fn (Obligation $record): string => $data->documentStatusColorFor($record))
                ->wrap();
            $columns[] = TextColumn::make('evidences_count')
                ->label('Qtd. Evidências')
                ->state(fn (Obligation $record): int => (int) ($record->evidences_count ?? 0))
                ->badge()
                ->color('gray');
        }

        $columns[] = TextColumn::make('source')
            ->label('Origem')
            ->badge()
            ->state(fn (Obligation $record): string => $record->extracted_obligation_id !== null ? 'Gerada pelo Termo' : 'Manual')
            ->color(fn (string $state): string => $state === 'Manual' ? 'gray' : 'info')
            ->toggleable();

        $filters = [
            SelectFilter::make('emission_id')
                ->label('Emissão')
                ->relationship('emission', 'name')
                ->searchable()
                ->preload(),
            SelectFilter::make('status')
                ->label('Status')
                ->options(Obligation::STATUS_OPTIONS),
            SelectFilter::make('responsible_user_id')
                ->label('Responsável')
                ->relationship('responsibleUser', 'name')
                ->searchable()
                ->preload(),
            SelectFilter::make('responsible_area')
                ->label('Área responsável')
                ->options(fn (): array => collect(Obligation::query()
                    ->whereNotNull('responsible_area')
                    ->orderBy('responsible_area')
                    ->pluck('responsible_area', 'responsible_area')
                    ->all())->union(ObligationFormFields::AREA_OPTIONS)->all()),
            SelectFilter::make('priority')
                ->label('Prioridade')
                ->options(Obligation::PRIORITY_OPTIONS),
            SelectFilter::make('due_window')
                ->label('Vencimento')
                ->options(ObligationDashboardData::DUE_WINDOW_OPTIONS)
                ->query(fn (Builder $query, array $data): Builder => app(ObligationDashboardData::class)->applyDueWindowFilter($query, $data['value'] ?? null)),
            SelectFilter::make('operational_focus')
                ->label('Visão operacional')
                ->options($data->operationalFocusOptions($canViewEvidence))
                ->query(fn (Builder $query, array $data): Builder => app(ObligationDashboardData::class)->applyOperationalFocusFilter($query, $data['value'] ?? null)),
            TernaryFilter::make('has_responsible')
                ->label('Responsável definido')
                ->placeholder('Todos')
                ->trueLabel('Com responsável')
                ->falseLabel('Sem responsável')
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereNotNull('responsible_user_id'),
                    false: fn (Builder $query): Builder => $query->whereNull('responsible_user_id'),
                    blank: fn (Builder $query): Builder => $query,
                ),
            SelectFilter::make('source')
                ->label('Origem')
                ->options(ObligationDashboardData::SOURCE_FILTER_OPTIONS)
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'term' => $query->whereNotNull('extracted_obligation_id'),
                        'manual' => $query->whereNull('extracted_obligation_id'),
                        default => $query,
                    };
                }),
        ];

        if ($canViewEvidence) {
            $filters[] = SelectFilter::make('evidence_state')
                ->label('Status das evidências')
                ->options(ObligationDashboardData::EVIDENCE_FILTER_OPTIONS)
                ->query(fn (Builder $query, array $data): Builder => app(ObligationDashboardData::class)->applyEvidenceFilter($query, $data['value'] ?? null));
        }

        return $table
            ->query($data->operationalQuery(
                filters: $pageFilters,
                includeConcludedWithoutApprovedEvidence: $canViewEvidence,
            ))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['emission', 'responsibleUser']))
            ->recordUrl($obligationUrl)
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->columns($columns)
            ->filters($filters)
            ->headerActions([
                ExportAction::make()
                    ->label('Exportar visão atual')
                    ->authorize(fn (): bool => $canExport)
                    ->columnMapping(false)
                    ->exporter(ObligationExporter::class),
            ])
            ->emptyStateHeading('Nenhuma obrigação pendente')
            ->emptyStateDescription('Não há obrigações em aberto que exijam atenção no momento.');
    }
}
