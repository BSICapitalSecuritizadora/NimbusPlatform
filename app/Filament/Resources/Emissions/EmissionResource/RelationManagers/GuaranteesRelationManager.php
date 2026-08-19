<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\DTOs\Guarantees\EmissionGuaranteePositionData;
use App\DTOs\Guarantees\GuaranteePositionData;
use App\Enums\AccessPermission;
use App\Enums\GuaranteeCategory;
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
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['construction', 'fund', 'documentReferences', 'pendingDetections']))
            ->columns([
                TextColumn::make('name')
                    ->label('Garantia')
                    ->description(fn (Guarantee $record): string => GuaranteeType::labelFor($record->type))
                    ->formatStateUsing(fn (?string $state, Guarantee $record): string => $record->display_name)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('identification')
                    ->label('Identificação')
                    ->formatStateUsing(fn (mixed $state): string => $this->formatIdentification($state))
                    ->placeholder('Não informada')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('requirement_basis')
                    ->label('Regra Contratual')
                    ->formatStateUsing(fn (Guarantee $record): string => $this->requirementLabel($record))
                    ->placeholder('Sem mínimo contratual')
                    ->wrap(),
                TextColumn::make('contracted_value')
                    ->label('Valor na Contratação')
                    ->formatStateUsing(fn (mixed $state): string => $this->money($state))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('current_value')
                    ->label('Valor Atual')
                    ->state(fn (Guarantee $record): string => $this->money($this->positionFor($record)?->currentValue()))
                    ->alignEnd(),
                TextColumn::make('eligible_value')
                    ->label('Valor Elegível')
                    ->state(fn (Guarantee $record): string => $this->money($this->positionFor($record)?->eligibleValue))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('coverage')
                    ->label('Cobertura')
                    ->state(fn (Guarantee $record): string => $this->ratio($this->positionFor($record)?->coverageRatio))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('validity_end_date')
                    ->label('Vigência')
                    ->formatStateUsing(fn (mixed $state, Guarantee $record): string => $this->validityLabel($record))
                    ->placeholder('Sem prazo definido')
                    ->toggleable(),
                TextColumn::make('value_source')
                    ->label('Fonte')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Guarantee $record): string => $record->resolvedValueSource()->label())
                    ->toggleable(),
                TextColumn::make('documentation_status')
                    ->label('Documentação')
                    ->badge()
                    ->state(fn (Guarantee $record): string => $record->documentationStatus()->shortLabel())
                    ->color(fn (Guarantee $record): string => $record->documentationStatus()->color())
                    ->tooltip(fn (Guarantee $record): string => $record->documentationStatus()->label())
                    ->toggleable(),
                TextColumn::make('legal_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (Guarantee $record): string => $record->legal_status?->label() ?? '—')
                    ->color(fn (Guarantee $record): string => $record->legal_status?->color() ?? 'gray'),
            ])
            ->defaultSort('created_at', 'desc')
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
                $this->makeRecordValuationAction(),
                $this->makeInformValueAction(),
                EditAction::make()
                    ->modalHeading('Editar garantia')
                    ->authorize(fn (): bool => $this->userCanUpdate()),
                DeleteAction::make()
                    ->authorize(fn (): bool => $this->userCanDelete()),
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
            ->color('warning')
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

    protected function requirementLabel(Guarantee $guarantee): string
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
            return 'Não informado';
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
