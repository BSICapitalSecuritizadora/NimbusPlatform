<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\Models\Emission;
use App\Models\GuaranteeSnapshot;
use App\Services\GuaranteeCoverageCalculator;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

class GuaranteesRelationManager extends RelationManager
{
    protected static string $relationship = 'guarantees';

    protected static ?string $recordTitleAttribute = 'guarantee_type';

    protected static ?string $title = 'Garantias';

    protected static ?string $modelLabel = 'Garantia';

    protected static ?string $pluralModelLabel = 'Garantias';

    /**
     * @var array{
     *     canManageGuarantees: bool,
     *     canRegisterMonthlyIndicators: bool,
     *     history: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     latestSummary: array<string, mixed>|null,
     *     migrationPending: bool,
     *     needsMonthlyUpdate: bool
     * }|null
     */
    protected ?array $coverageOverview = null;

    public function mount(): void
    {
        parent::mount();

        if (! $this->getOwnerRecord() instanceof Emission) {
            return;
        }

        if (! $this->getOwnerRecord()->requiresMonthlyGuaranteeSnapshotUpdate()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Atualizacao mensal de garantias pendente.')
            ->body('Atualize o valor das quotas da competencia atual para manter a cobertura das garantias em dia.')
            ->persistent()
            ->send();
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
        if (! $ownerRecord instanceof Emission) {
            return null;
        }

        return $ownerRecord->requiresMonthlyGuaranteeSnapshotUpdate() ? 'warning' : null;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Emission) {
            return null;
        }

        return $ownerRecord->requiresMonthlyGuaranteeSnapshotUpdate()
            ? 'Atualize o valor das quotas do mes atual.'
            : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('guarantee_type')
                    ->label('Tipo de Garantia')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Ex: Alienação Fiduciária'),
                TextInput::make('minimum_value')
                    ->label('Valor Mínimo')
                    ->prefix('R$')
                    ->numeric()
                    ->required()
                    ->placeholder('0,00'),
                DatePicker::make('validity_start_date')
                    ->label('Início da Validade')
                    ->required(),
                DatePicker::make('validity_end_date')
                    ->label('Término da Validade')
                    ->required(),
                TextInput::make('evaluation_frequency')
                    ->label('Periodicidade de Avaliação')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Ex: Mensal'),
                Textarea::make('description')
                    ->label('Descrição')
                    ->placeholder('Descreva detalhadamente a garantia')
                    ->rows(4)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('guarantee_type')
            ->description('Atualize mensalmente o valor das quotas para consolidar a cobertura da emissao.')
            ->columns([
                TextColumn::make('guarantee_type')
                    ->label('Tipo de Garantia')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('minimum_value')
                    ->label('Valor Mínimo')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('validity_start_date')
                    ->label('Início da Validade')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('validity_end_date')
                    ->label('Término da Validade')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('evaluation_frequency')
                    ->label('Periodicidade de Avaliação')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(50)
                    ->wrap(),
            ])
            ->defaultSort('validity_start_date', 'desc')
            ->headerActions([
                $this->makeMonthlySnapshotAction(),
                CreateAction::make()
                    ->label('Cadastrar Garantia')
                    ->authorize(fn (): bool => $this->canManageGuarantees()),
            ])
            ->actions([
                EditAction::make()
                    ->authorize(fn (): bool => $this->canManageGuarantees()),
                DeleteAction::make()
                    ->authorize(fn (): bool => $this->canManageGuarantees()),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => $this->canManageGuarantees()),
                ]),
            ])
            ->emptyStateHeading('Nenhuma garantia cadastrada');
    }

    protected function getTableHeader(): ?View
    {
        return view(
            'filament.resources.emissions.relation-managers.guarantees-overview',
            $this->getCoverageOverview(),
        );
    }

    protected function makeMonthlySnapshotAction(): Action
    {
        return Action::make('update_monthly_snapshot')
            ->label('Atualizar indicadores mensais')
            ->icon('heroicon-o-chart-bar-square')
            ->color('warning')
            ->visible(fn (): bool => $this->canRegisterMonthlyIndicators())
            ->authorize(fn (): bool => $this->canManageGuarantees())
            ->modalWidth('2xl')
            ->modalHeading('Atualizar indicadores mensais')
            ->modalDescription('Informe manualmente o valor das quotas da competencia. O saldo devedor, as unidades, os recebiveis cedidos e o saldo das contas sao consolidados automaticamente a partir da emissao.')
            ->fillForm(fn (): array => $this->getMonthlySnapshotDefaults())
            ->form([
                TextInput::make('reference_month')
                    ->label('Mes')
                    ->placeholder('MM/AAAA')
                    ->mask('99/9999')
                    ->required()
                    ->formatStateUsing(fn (mixed $state): string => GuaranteeSnapshot::formatReferenceMonthForDisplay($state))
                    ->dehydrateStateUsing(fn (mixed $state): ?string => GuaranteeSnapshot::normalizeReferenceMonth($state))
                    ->mutateStateForValidationUsing(fn (mixed $state): ?string => GuaranteeSnapshot::normalizeReferenceMonth($state))
                    ->validationMessages([
                        'required' => 'Informe a competencia no formato MM/AAAA.',
                    ]),
                $this->makeCurrencyInput('quota_value', 'Valor das quotas'),
            ])
            ->action(function (array $data): void {
                if (! Emission::hasGuaranteeSnapshotsTable()) {
                    Notification::make()
                        ->title('Indicadores indisponiveis')
                        ->body('A tabela de indicadores mensais ainda nao foi criada. Execute a migration pendente e recarregue a pagina.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $referenceMonth = GuaranteeSnapshot::normalizeReferenceMonth($data['reference_month'] ?? null);

                if ($referenceMonth === null) {
                    Notification::make()
                        ->title('Indicadores nao atualizados')
                        ->body('Informe a competencia no formato MM/AAAA.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $outstandingBalance = app(GuaranteeCoverageCalculator::class)
                    ->calculateOutstandingBalanceForMonth($this->ownerRecord, $referenceMonth);

                $this->ownerRecord->guaranteeSnapshots()->updateOrCreate(
                    ['reference_month' => $referenceMonth],
                    [
                        'quota_value' => MoneyFormatter::normalizeDecimalValue($data['quota_value'] ?? null),
                        'outstanding_balance' => $outstandingBalance,
                    ],
                );

                $this->ownerRecord->unsetRelation('guaranteeSnapshots');
                $this->coverageOverview = null;

                Notification::make()
                    ->title('Indicadores mensais atualizados com sucesso.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array{canManageGuarantees: bool, canRegisterMonthlyIndicators: bool, history: \Illuminate\Support\Collection<int, array<string, mixed>>, latestSummary: array<string, mixed>|null, migrationPending: bool, needsMonthlyUpdate: bool}
     */
    protected function getCoverageOverview(): array
    {
        if ($this->coverageOverview !== null) {
            return $this->coverageOverview;
        }

        if (! Emission::hasGuaranteeSnapshotsTable()) {
            return $this->coverageOverview = [
                'canManageGuarantees' => $this->canManageGuarantees(),
                'canRegisterMonthlyIndicators' => false,
                'latestSummary' => null,
                'history' => collect(),
                'migrationPending' => true,
                'needsMonthlyUpdate' => false,
            ];
        }

        /** @var Emission $emission */
        $emission = $this->getOwnerRecord();
        $calculator = app(GuaranteeCoverageCalculator::class);
        $history = $calculator->buildHistory($emission);

        return $this->coverageOverview = [
            'canManageGuarantees' => $this->canManageGuarantees(),
            'canRegisterMonthlyIndicators' => $this->canRegisterMonthlyIndicators(),
            'latestSummary' => $history->first(),
            'history' => $history,
            'migrationPending' => false,
            'needsMonthlyUpdate' => $emission->requiresMonthlyGuaranteeSnapshotUpdate(),
        ];
    }

    /**
     * @return array{quota_value: string|null, reference_month: string}
     */
    protected function getMonthlySnapshotDefaults(): array
    {
        if (! Emission::hasGuaranteeSnapshotsTable()) {
            return [
                'reference_month' => GuaranteeSnapshot::formatReferenceMonthForDisplay(now()->startOfMonth()->toDateString()),
                'quota_value' => null,
            ];
        }

        $currentMonth = now()->startOfMonth()->toDateString();

        $currentSnapshot = $this->ownerRecord->guaranteeSnapshots()
            ->whereDate('reference_month', $currentMonth)
            ->first();

        if ($currentSnapshot instanceof GuaranteeSnapshot) {
            return [
                'reference_month' => GuaranteeSnapshot::formatReferenceMonthForDisplay($currentSnapshot->reference_month),
                'quota_value' => $this->formatCurrency($currentSnapshot->quota_value),
            ];
        }

        $latestSnapshot = $this->ownerRecord->guaranteeSnapshots()
            ->latest('reference_month')
            ->first();

        return [
            'reference_month' => GuaranteeSnapshot::formatReferenceMonthForDisplay($currentMonth),
            'quota_value' => $this->formatCurrency($latestSnapshot?->quota_value),
        ];
    }

    protected function makeCurrencyInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => $this->formatCurrency($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => $this->normalizeCurrency($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => $this->normalizeCurrency($state))
            ->minValue(0)
            ->placeholder('0,00')
            ->validationMessages([
                'required' => "Informe {$label}.",
                'min' => "{$label} nao pode ser negativo.",
            ]);
    }

    protected function normalizeCurrency(mixed $state): ?float
    {
        if ($state === null) {
            return null;
        }

        if (is_string($state) && (trim($state) === '')) {
            return null;
        }

        return MoneyFormatter::normalizeDecimalValue($state);
    }

    protected function formatCurrency(mixed $state): ?string
    {
        if ($state === null) {
            return null;
        }

        if (is_string($state) && (trim($state) === '')) {
            return null;
        }

        return MoneyFormatter::formatCurrencyForDisplay($state);
    }

    protected function canManageGuarantees(): bool
    {
        return auth()->user()?->can('emissions.update') ?? false;
    }

    protected function canRegisterMonthlyIndicators(): bool
    {
        return $this->canManageGuarantees() && Emission::hasGuaranteeSnapshotsTable();
    }
}
