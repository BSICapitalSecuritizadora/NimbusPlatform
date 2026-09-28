<?php

namespace App\Filament\Resources\MeasurementFinancialRules;

use App\Enums\AccessPermission;
use App\Filament\Resources\MeasurementFinancialRules\Pages\ManageMeasurementFinancialRules;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementFinancialRule;
use App\Services\MeasurementFinancialReconciliationService;
use App\Services\MeasurementFinancialRuleService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class MeasurementFinancialRuleResource extends Resource
{
    protected static ?string $model = MeasurementFinancialRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Regras Financeiras de Medições';

    protected static ?string $modelLabel = 'Regra financeira';

    protected static ?string $pluralModelLabel = 'Regras Financeiras de Medições';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Obras';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::ruleFields());
    }

    /** @return array<Section> */
    public static function ruleFields(): array
    {
        return [
            Section::make('Aplicação')->columns(2)->columnSpanFull()->schema([
                Select::make('emission_id')->label('Emissão')->required()->searchable()->preload()
                    ->options(fn (): array => Emission::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->live()->afterStateUpdated(fn (Set $set) => $set('construction_id', null)),
                Select::make('construction_id')->label('Obra')->searchable()->preload()
                    ->placeholder('Todas as obras da emissão')->disabled(fn (Get $get): bool => blank($get('emission_id')))
                    ->options(fn (Get $get): array => Construction::query()->where('emission_id', $get('emission_id'))
                        ->orderBy('development_name')->pluck('development_name', 'id')->all()),
                TextInput::make('name')->label('Nome da regra')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->label('Condições de aplicação')->required()->rows(3)->maxLength(5000)->columnSpanFull(),
            ]),
            Section::make('Limites e conferência')->columns(2)->columnSpanFull()
                ->description('Defina pelo menos um limite. Quando os dois forem informados, ambos precisam ser atendidos. Toda divergência exige justificativa e aceite do Finalizador.')
                ->schema([
                    Select::make('direction')->label('Diferença permitida')->options(MeasurementFinancialRule::DIRECTIONS)->required(),
                    Toggle::make('requires_document')->label('Exigir documento de suporte')->default(false),
                    TextInput::make('maximum_difference_amount')->label('Diferença máxima em reais')->prefix('R$')->numeric()->minValue(0)->requiredWithout('maximum_difference_percent'),
                    TextInput::make('maximum_difference_percent')->label('Diferença máxima em percentual')->suffix('%')->numeric()->minValue(0)
                        ->requiredWithout('maximum_difference_amount')->helperText('Calculado sobre o saldo esperado antes deste pagamento. Não se aplica quando o saldo é zero.'),
                    DatePicker::make('effective_from')->label('Válida a partir de')->required()->helperText('A vigência considera a data do pagamento.'),
                    DatePicker::make('effective_until')->label('Válida até')->afterOrEqual('effective_from'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->recordTitleAttribute('name')->defaultSort('id', 'desc')
            ->emptyStateHeading('Nenhuma regra financeira cadastrada')
            ->emptyStateDescription('Cadastre as condições previstas para uma emissão. Casos sem regra continuam sujeitos à justificativa e conferência.')
            ->columns([
                TextColumn::make('emission.name')->label('Emissão')->searchable()->sortable()->wrap(),
                TextColumn::make('name')->label('Regra')->searchable()->wrap()->description(fn (MeasurementFinancialRule $record): string => 'Versão '.$record->version),
                TextColumn::make('construction.development_name')->label('Obra')->placeholder('Todas as obras')->wrap(),
                TextColumn::make('direction')->label('Direção')->formatStateUsing(fn (string $state): string => MeasurementFinancialRule::DIRECTIONS[$state]),
                TextColumn::make('maximum_difference_amount')->label('Limite em reais')->placeholder('—')
                    ->formatStateUsing(fn (string $state): string => MeasurementFinancialReconciliationService::formatCurrency($state)),
                TextColumn::make('maximum_difference_percent')->label('Limite percentual')->suffix('%')->placeholder('—'),
                TextColumn::make('effective_from')->label('Início')->date('d/m/Y')->sortable(),
                TextColumn::make('effective_until')->label('Fim')->date('d/m/Y')->placeholder('Sem fim definido'),
                TextColumn::make('retired_at')->label('Disponibilidade')->badge()
                    ->state(fn (MeasurementFinancialRule $record): string => $record->retired_at ? 'Encerrada' : 'Ativa')
                    ->color(fn (string $state): string => $state === 'Ativa' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('emission_id')->label('Emissão')->relationship('emission', 'name')->searchable()->preload(),
                TernaryFilter::make('retired_at')->label('Encerradas')->nullable(),
            ])
            ->recordActions([
                Action::make('details')->label('Ver condições')->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (MeasurementFinancialRule $record) => view('filament.infolists.measurement-financial-rule', ['rule' => $record])),
                Action::make('newVersion')->label('Nova versão')->icon('heroicon-o-document-duplicate')
                    ->visible(fn (MeasurementFinancialRule $record): bool => static::canCreate() && $record->retired_at === null)
                    ->modalDescription('A versão anterior deixa de aceitar novos pagamentos. As condições já aplicadas permanecem no histórico.')
                    ->fillForm(fn (MeasurementFinancialRule $record): array => $record->snapshot())
                    ->schema(static::ruleFields())
                    ->action(fn (MeasurementFinancialRule $record, array $data) => static::withValidationFeedback(fn () => app(MeasurementFinancialRuleService::class)->register($data, auth()->user(), $record))),
                Action::make('retire')->label('Encerrar')->color('danger')->requiresConfirmation()
                    ->modalDescription('A regra deixará de estar disponível para novos enquadramentos. Pagamentos anteriores permanecem preservados.')
                    ->visible(fn (MeasurementFinancialRule $record): bool => static::canCreate() && $record->retired_at === null)
                    ->action(fn (MeasurementFinancialRule $record) => static::withValidationFeedback(fn () => app(MeasurementFinancialRuleService::class)->retire($record, auth()->user()))),
            ]);
    }

    public static function withValidationFeedback(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Regra não salva')
                ->body(collect($exception->errors())->flatten()->implode(' '))->persistent()->send();
            throw new Halt;
        }
    }

    public static function canViewAny(): bool
    {
        return (auth()->user()?->can(AccessPermission::MeasurementsFinancialRulesView->value) ?? false) || static::canCreate();
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(AccessPermission::MeasurementsFinancialRulesManage->value) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['emission', 'construction', 'createdBy'])
            ->when(! static::canViewAny(), fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMeasurementFinancialRules::route('/'),
        ];
    }
}
