<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\DTOs\Guarantees\EmissionGuaranteePositionData;
use App\DTOs\Guarantees\GuaranteePositionData;
use App\Enums\AccessPermission;
use App\Enums\GuaranteeCategory;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
use App\Enums\GuaranteeValuationBasis;
use App\Enums\GuaranteeValueSource;
use App\Filament\Resources\Emissions\Schemas\GuaranteeFormFields;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeSnapshot;
use App\Services\Guarantees\EmissionGuaranteeCoverageEngine;
use App\Services\Guarantees\GuaranteeAlertBuilder;
use App\Services\Guarantees\GuaranteeSnapshotWriter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Aba de Garantias da emissão.
 *
 * A hierarquia segue §44 do escopo: resumo executivo, pendências, garantias
 * detectadas, garantias vigentes, posição da competência e evolução histórica.
 * Nenhum cálculo acontece aqui — a tela consome
 * {@see EmissionGuaranteeCoverageEngine} e apenas apresenta o resultado.
 */
class GuaranteesRelationManager extends RelationManager
{
    protected static string $relationship = 'guarantees';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Garantias';

    protected static ?string $modelLabel = 'Garantia';

    protected static ?string $pluralModelLabel = 'Garantias';

    protected ?EmissionGuaranteePositionData $positionCache = null;

    /** @var Collection<int, GuaranteePositionData>|null */
    protected ?Collection $positionsByGuarantee = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::GuaranteesView->value) ?? false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Emission) {
            return null;
        }

        return $ownerRecord->requiresMonthlyGuaranteeSnapshotUpdate() ? 'Pendente' : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Emission) {
            return null;
        }

        return $ownerRecord->requiresMonthlyGuaranteeSnapshotUpdate()
            ? 'A competência atual ainda não foi consolidada.'
            : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(GuaranteeFormFields::make())->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'bsi-guarantees-table'])
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['construction', 'fund', 'documentReferences', 'pendingDetections']))
            ->columns([
                TextColumn::make('name')
                    ->label('Garantia / Identificação')
                    ->formatStateUsing(function (?string $state, Guarantee $record): HtmlString {
                        $name = e($record->display_name);
                        $subtitle = $this->guaranteeSubtitle($record);
                        $identificationText = $this->formatIdentificationSummary($record->identification);
                        $docCount = $record->relationLoaded('documentReferences')
                            ? $record->documentReferences->count()
                            : $record->documentReferences()->count();

                        $html = '<div class="flex flex-col py-0.5 min-w-[280px] max-w-[500px] space-y-0.5">';
                        $html .= '<div class="flex items-baseline gap-2 flex-wrap">';
                        $html .= '<span class="font-semibold text-sm text-[#fbfaf8] leading-snug tracking-tight hover:text-amber-300/90 transition-colors">'.$name.'</span>';
                        if ($docCount > 0) {
                            $html .= '<span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium bg-[#081a22] text-slate-400 border border-[#1d4554]/40 shrink-0" title="'.$docCount.' documento(s) associado(s)">';
                            $html .= '<svg class="w-3 h-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>';
                            $html .= $docCount.' doc(s)';
                            $html .= '</span>';
                        }
                        $html .= '</div>';

                        if ($subtitle) {
                            $html .= '<div class="text-xs text-slate-400 font-normal leading-normal">'.e($subtitle).'</div>';
                        }

                        if ($identificationText) {
                            $html .= '<div class="text-[11px] text-slate-400/90 font-mono leading-normal flex items-center gap-1.5 mt-0.5">';
                            $html .= '<span class="inline-block w-1.5 h-1.5 rounded-full bg-[#1d4554] shrink-0"></span>';
                            $html .= '<span>'.e($identificationText).'</span>';
                            $html .= '</div>';
                        }

                        $html .= '</div>';

                        return new HtmlString($html);
                    })
                    ->searchable()
                    ->grow()
                    ->tooltip(fn (Guarantee $record): string => $record->display_name),
                TextColumn::make('current_value')
                    ->label('Valor Atual')
                    ->state(fn (Guarantee $record): ?float => $this->positionFor($record)?->currentValue())
                    ->formatStateUsing(fn (?float $state): HtmlString => new HtmlString(
                        $state === null
                            ? '<span class="text-slate-500 font-normal select-none" title="Sem valor atualizado">—</span>'
                            : '<span class="text-sm font-bold text-[#fbfaf8] tabular-nums font-mono whitespace-nowrap">R$ '.MoneyFormatter::formatCurrencyForDisplay($state).'</span>'
                    ))
                    ->alignEnd(),
                TextColumn::make('contracted_value')
                    ->label('Valor Contratação')
                    ->formatStateUsing(fn (mixed $state): HtmlString => new HtmlString(
                        $state === null || $state === ''
                            ? '<span class="text-slate-500 font-normal select-none">—</span>'
                            : '<span class="text-xs font-normal text-slate-400 tabular-nums font-mono whitespace-nowrap">R$ '.MoneyFormatter::formatCurrencyForDisplay($state).'</span>'
                    ))
                    ->alignEnd(),
                TextColumn::make('coverage')
                    ->label('Cobertura')
                    ->state(fn (Guarantee $record): ?float => $this->positionFor($record)?->coverageRatio)
                    ->formatStateUsing(function (?float $state, Guarantee $record): HtmlString {
                        if ($state === null) {
                            return new HtmlString('<span class="text-slate-500 font-normal select-none">—</span>');
                        }
                        $percent = number_format($state * 100, 2, ',', '.').'%';
                        $statusColor = $this->positionFor($record)?->coverageStatus?->color();
                        $color = match ($statusColor) {
                            'success' => 'text-emerald-400',
                            'warning' => 'text-amber-400/90',
                            'danger' => 'text-rose-400',
                            default => 'text-slate-300',
                        };
                        $barColor = match ($statusColor) {
                            'success' => 'bg-emerald-400',
                            'warning' => 'bg-amber-400/90',
                            'danger' => 'bg-rose-400',
                            default => 'bg-slate-400',
                        };
                        $fillWidth = min(100, max(0, round($state * 100)));

                        return new HtmlString(
                            '<div class="flex flex-col items-end gap-1">
                                <span class="font-bold text-xs font-mono '.$color.' tabular-nums whitespace-nowrap">'.$percent.'</span>
                                <div class="w-14 h-1 bg-[#091b23] rounded-full overflow-hidden border border-[#1d4554]/40">
                                    <div class="h-full rounded-full '.$barColor.'" style="width: '.$fillWidth.'%"></div>
                                </div>
                            </div>'
                        );
                    })
                    ->alignEnd(),
                TextColumn::make('legal_status')
                    ->label('Status')
                    ->formatStateUsing(function (Guarantee $record): HtmlString {
                        if (! $record->legal_status) {
                            return new HtmlString('<span class="text-slate-500 font-normal select-none">—</span>');
                        }

                        $color = $record->legal_status->color();
                        $classes = match ($color) {
                            'success' => 'bg-emerald-950/40 text-emerald-300 border-emerald-500/30',
                            'info' => 'bg-[#0f2c38] text-cyan-300 border-[#1d4554]/60',
                            'warning' => 'bg-amber-950/40 text-amber-300/90 border-amber-500/30',
                            'danger' => 'bg-rose-950/50 text-rose-300 border-rose-500/30',
                            default => 'bg-[#081a22] text-slate-400 border-[#1d4554]/40',
                        };

                        return new HtmlString(
                            '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-medium tracking-wide border '.$classes.' whitespace-nowrap">'.
                            e($record->legal_status->label()).
                            '</span>'
                        );
                    }),
                TextColumn::make('requirement_basis')
                    ->label('Regra Contratual')
                    ->formatStateUsing(fn (Guarantee $record): HtmlString => new HtmlString(
                        '<span class="text-xs text-slate-300/80 leading-snug line-clamp-2 max-w-[260px] block" title="'.e($this->requirementFullDescription($record)).'">'.
                        e($this->requirementLabel($record)).
                        '</span>'
                    ))
                    ->placeholder('—')
                    ->tooltip(fn (Guarantee $record): string => $this->requirementFullDescription($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('eligible_value')
                    ->label('Valor Elegível')
                    ->state(fn (Guarantee $record): ?float => $this->positionFor($record)?->eligibleValue)
                    ->formatStateUsing(fn (?float $state): HtmlString => new HtmlString(
                        $state === null
                            ? '<span class="text-slate-500 font-normal select-none">—</span>'
                            : '<span class="text-xs font-normal text-slate-300 tabular-nums font-mono whitespace-nowrap">R$ '.MoneyFormatter::formatCurrencyForDisplay($state).'</span>'
                    ))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('validity_end_date')
                    ->label('Vigência')
                    ->formatStateUsing(fn (mixed $state, Guarantee $record): HtmlString => new HtmlString(
                        '<span class="text-xs text-slate-400 tabular-nums whitespace-nowrap">'.e($this->validityLabel($record)).'</span>'
                    ))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('identification')
                    ->label('Identificação')
                    ->formatStateUsing(fn (mixed $state): string => $this->formatIdentification($state))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('value_source')
                    ->label('Fonte')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Guarantee $record): string => $record->resolvedValueSource()->label())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('documentation_status')
                    ->label('Documentação')
                    ->badge()
                    ->state(fn (Guarantee $record): string => $record->documentationStatus()->shortLabel())
                    ->color(fn (Guarantee $record): string => $record->documentationStatus()->color())
                    ->tooltip(fn (Guarantee $record): string => $record->documentationStatus()->label())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Buscar por garantia...')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo de garantia')
                    ->options(GuaranteeType::options()),
                SelectFilter::make('legal_status')
                    ->label('Status jurídico')
                    ->options(GuaranteeLegalStatus::options()),
            ])
            ->groups([
                Group::make('type')
                    ->label('Tipo de garantia')
                    ->getTitleFromRecordUsing(fn (Guarantee $record): string => GuaranteeType::labelFor($record->type))
                    ->collapsible(),
            ])
            ->headerActions([
                $this->makeUpdateCompetenceAction(),
                $this->makeCloseCompetenceAction(),
                CreateAction::make()
                    ->label('Cadastrar garantia')
                    ->modalHeading('Cadastrar garantia')
                    ->authorize(fn (): bool => $this->userCanCreate()),
            ])
            ->actions([
                $this->makeViewDetailAction(),
                ActionGroup::make([
                    $this->makeRecordValuationAction(),
                    $this->makeInformValueAction(),
                    EditAction::make()
                        ->modalHeading('Editar garantia')
                        ->authorize(fn (): bool => $this->userCanUpdate()),
                    DeleteAction::make()
                        ->authorize(fn (): bool => $this->userCanDelete()),
                ])
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Mais opções'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => $this->userCanDelete()),
                ]),
            ])
            ->emptyStateHeading('Nenhuma garantia cadastrada')
            ->emptyStateDescription('Cadastre manualmente ou use "Identificar nos documentos" na aba Garantias Detectadas para importar do Termo e dos aditamentos.');
    }

    protected function getTableHeader(): ?View
    {
        $emission = $this->getOwnerRecord();
        $position = $this->position();

        return view('filament.resources.emissions.relation-managers.guarantees-overview', [
            'position' => $position,
            'alerts' => app(GuaranteeAlertBuilder::class)->build($emission, $position),
            'history' => app(EmissionGuaranteeCoverageEngine::class)->history($emission),
            'pendingDetections' => $emission->extractedGuarantees()->pending()->count(),
            'canUpdateValues' => $this->canUpdateValues(),
            'canCloseCompetence' => $this->canCloseCompetence(),
            'canCreate' => $this->userCanCreate(),
            'isCompetenceClosed' => $this->isCompetenceClosed(),
        ]);
    }

    /**
     * Posição consolidada da competência corrente, apurada uma vez por request.
     */
    protected function position(): EmissionGuaranteePositionData
    {
        return $this->positionCache ??= app(EmissionGuaranteeCoverageEngine::class)
            ->buildPosition($this->getOwnerRecord());
    }

    protected function positionFor(Guarantee $guarantee): ?GuaranteePositionData
    {
        $this->positionsByGuarantee ??= $this->position()->positions
            ->keyBy(fn (GuaranteePositionData $position): int => $position->guarantee->getKey());

        return $this->positionsByGuarantee->get($guarantee->getKey());
    }

    protected function makeViewDetailAction(): Action
    {
        return Action::make('view_detail')
            ->label('Detalhes')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->iconButton()
            ->tooltip('Ver detalhes da garantia')
            ->modalHeading(fn (Guarantee $record): string => $record->display_name)
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (Guarantee $record): View => view(
                'filament.resources.emissions.relation-managers.guarantee-detail',
                [
                    'guarantee' => $record->load([
                        'documentReferences.document',
                        'events.documentReference',
                        'valuations',
                        'monthlyPositions',
                        'construction',
                        'fund',
                    ]),
                    'position' => $this->positionFor($record),
                ],
            ));
    }

    /**
     * Registro de avaliação (§20). É o que alimenta o valor atual das garantias
     * cuja origem é laudo, e não dado operacional.
     */
    protected function makeRecordValuationAction(): Action
    {
        return Action::make('record_valuation')
            ->label('Registrar avaliação')
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->visible(fn (Guarantee $record): bool => $record->resolvedValueSource() === GuaranteeValueSource::Valuation)
            ->authorize(fn (): bool => $this->canManageValuations())
            ->modalHeading('Registrar avaliação da garantia')
            ->modalDescription('A avaliação vigente na competência analisada é a usada no cálculo. Laudos posteriores não alteram meses já apurados.')
            ->form([
                DatePicker::make('valuation_date')
                    ->label('Data-base da avaliação')
                    ->required()
                    ->default(now()),
                $this->currencyInput('value', 'Valor avaliado')->required(),
                Select::make('basis')
                    ->label('Critério')
                    ->options(GuaranteeValuationBasis::options())
                    ->default(GuaranteeValuationBasis::Appraisal->value)
                    ->required(),
                TextInput::make('appraiser')->label('Avaliador')->maxLength(255),
                DatePicker::make('valid_until')->label('Válida até'),
                Textarea::make('notes')->label('Observações')->rows(2),
            ])
            ->action(function (Guarantee $record, array $data): void {
                $record->valuations()->create([
                    'valuation_date' => $data['valuation_date'],
                    'value' => MoneyFormatter::normalizeDecimalValue($data['value']),
                    'basis' => $data['basis'],
                    'appraiser' => $data['appraiser'] ?? null,
                    'valid_until' => $data['valid_until'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'recorded_by' => auth()->id(),
                ]);

                $this->resetPositionCache();

                Notification::make()->title('Avaliação registrada.')->success()->send();
            });
    }

    /**
     * Digitação do valor da competência, oferecida apenas onde o Nimbus não
     * consegue apurar sozinho (§21 e §22).
     */
    protected function makeInformValueAction(): Action
    {
        return Action::make('inform_value')
            ->label('Informar valor do mês')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (Guarantee $record): bool => $record->resolvedValueSource() === GuaranteeValueSource::Manual)
            ->authorize(fn (): bool => $this->canUpdateValues())
            ->modalHeading('Informar valor da competência')
            ->fillForm(fn (): array => [
                'reference_month' => GuaranteeSnapshot::formatReferenceMonthForDisplay(now()->startOfMonth()),
            ])
            ->form([
                TextInput::make('reference_month')
                    ->label('Competência')
                    ->placeholder('MM/AAAA')
                    ->mask('99/9999')
                    ->required(),
                $this->currencyInput('current_value', 'Valor da garantia')->required(),
            ])
            ->action(function (Guarantee $record, array $data): void {
                app(GuaranteeSnapshotWriter::class)->recordManualValue(
                    guarantee: $record,
                    referenceMonth: $data['reference_month'],
                    value: MoneyFormatter::normalizeDecimalValue($data['current_value']),
                    actor: auth()->user(),
                );

                $this->resetPositionCache();

                Notification::make()->title('Valor da competência atualizado.')->success()->send();
            });
    }

    protected function makeUpdateCompetenceAction(): Action
    {
        return Action::make('update_competence')
            ->label('Atualizar competência')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->authorize(fn (): bool => $this->canUpdateValues())
            ->requiresConfirmation()
            ->modalHeading('Atualizar a posição da competência')
            ->modalDescription('O sistema consolida saldo devedor, recebíveis, estoque, contas e avaliações da competência atual e grava o snapshot. Valores sem fonte automática permanecem pendentes de digitação.')
            ->modalSubmitActionLabel('Atualizar')
            ->action(function (): void {
                app(GuaranteeSnapshotWriter::class)->persist(
                    emission: $this->getOwnerRecord(),
                    actor: auth()->user(),
                );

                $this->resetPositionCache();

                Notification::make()->title('Posição da competência atualizada.')->success()->send();
            });
    }

    protected function makeCloseCompetenceAction(): Action
    {
        return Action::make('close_competence')
            ->label('Fechar competência')
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->authorize(fn (): bool => $this->canCloseCompetence())
            ->visible(fn (): bool => ! $this->isCompetenceClosed())
            ->requiresConfirmation()
            ->modalHeading('Fechar a competência')
            ->modalDescription('O indicador do mês passa a ser imutável e é o que os relatórios usarão. Reabrir depois exige permissão específica e fica registrado na auditoria.')
            ->modalSubmitActionLabel('Fechar competência')
            ->action(function (): void {
                app(GuaranteeSnapshotWriter::class)->close(
                    emission: $this->getOwnerRecord(),
                    referenceMonth: $this->position()->referenceMonth,
                    actor: auth()->user(),
                );

                $this->resetPositionCache();

                Notification::make()->title('Competência fechada.')->success()->send();
            });
    }

    protected function isCompetenceClosed(): bool
    {
        return $this->getOwnerRecord()
            ->guaranteeSnapshots()
            ->whereDate('reference_month', $this->position()->referenceMonth)
            ->whereNotNull('closed_at')
            ->exists();
    }

    protected function resetPositionCache(): void
    {
        $this->positionCache = null;
        $this->positionsByGuarantee = null;
        $this->getOwnerRecord()->unsetRelation('guaranteeSnapshots');
    }

    protected function formatIdentification(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return '—';
        }

        return collect($state)
            ->filter(fn (mixed $value): bool => filled($value))
            ->take(3)
            ->map(fn (mixed $value, string $key): string => sprintf('%s: %s', $this->identificationLabel($key), $value))
            ->implode(' · ');
    }

    protected function formatIdentificationSummary(mixed $state): ?string
    {
        if (! is_array($state) || $state === []) {
            return null;
        }

        $formatted = collect($state)
            ->filter(fn (mixed $value): bool => filled($value))
            ->take(3)
            ->map(fn (mixed $value, string $key): string => sprintf('%s: %s', $this->identificationLabel($key), $value))
            ->implode(' · ');

        return filled($formatted) ? $formatted : null;
    }

    protected function identificationLabel(string $key): string
    {
        foreach (GuaranteeCategory::cases() as $category) {
            $fields = $category->identificationFields();

            if (isset($fields[$key])) {
                return $fields[$key];
            }
        }

        return str($key)->replace('_', ' ')->title()->toString();
    }

    protected function guaranteeSubtitle(Guarantee $record): ?string
    {
        $parts = [];
        if ($record->construction?->name) {
            $parts[] = $record->construction->name;
        } elseif ($record->fund?->display_name) {
            $parts[] = $record->fund->display_name;
        }
        $type = GuaranteeType::labelFor($record->type);
        if ($type && $type !== $record->display_name) {
            $parts[] = $type;
        }

        return ! empty($parts) ? implode(' · ', $parts) : null;
    }

    protected function requirementLabel(Guarantee $guarantee): string
    {
        $position = $this->positionFor($guarantee);

        if ($guarantee->requirement_value !== null && $guarantee->requirement_basis === GuaranteeRequirementBasis::Absolute) {
            return $this->money($guarantee->requirement_value);
        }

        if ($guarantee->requirement_percentage !== null && $guarantee->requirement_basis === GuaranteeRequirementBasis::Percentage) {
            $baseLabel = match ($guarantee->requirement_base) {
                'outstanding_balance' => 'do saldo devedor',
                'issued_volume' => 'do volume emitido',
                default => '',
            };

            return number_format((float) $guarantee->requirement_percentage * 100, 2, ',', '.').'% '.$baseLabel;
        }

        return $position?->requirement->description
            ?? $guarantee->requirement_formula
            ?? ($guarantee->requirement_value === null ? '—' : $this->money($guarantee->requirement_value));
    }

    protected function requirementFullDescription(Guarantee $guarantee): string
    {
        $position = $this->positionFor($guarantee);

        return $position?->requirement->description
            ?? $guarantee->requirement_formula
            ?? ($guarantee->requirement_value === null ? '—' : $this->money($guarantee->requirement_value));
    }

    protected function validityLabel(Guarantee $guarantee): string
    {
        $start = $guarantee->validity_start_date?->format('d/m/Y');
        $end = $guarantee->validity_end_date?->format('d/m/Y');

        return match (true) {
            $start !== null && $end !== null => "{$start} — {$end}",
            $start !== null => "Desde {$start}",
            $end !== null => "Até {$end}",
            default => '—',
        };
    }

    /**
     * Ausência é dita, não convertida em zero (§25).
     */
    protected function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value);
    }

    protected function ratio(?float $value): string
    {
        return $value === null ? '—' : number_format($value * 100, 2, ',', '.').'%';
    }

    protected function currencyInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->minValue(0)
            ->placeholder('0,00');
    }

    protected function userCanCreate(): bool
    {
        return auth()->user()?->can(AccessPermission::GuaranteesCreate->value) ?? false;
    }

    protected function userCanUpdate(): bool
    {
        return auth()->user()?->can(AccessPermission::GuaranteesUpdate->value) ?? false;
    }

    protected function userCanDelete(): bool
    {
        return auth()->user()?->can(AccessPermission::GuaranteesDelete->value) ?? false;
    }

    protected function canUpdateValues(): bool
    {
        return auth()->user()?->can(AccessPermission::GuaranteesUpdateValue->value) ?? false;
    }

    protected function canCloseCompetence(): bool
    {
        return auth()->user()?->can(AccessPermission::GuaranteesCloseCompetence->value) ?? false;
    }

    protected function canManageValuations(): bool
    {
        return auth()->user()?->can(AccessPermission::GuaranteesManageValuations->value) ?? false;
    }
}
