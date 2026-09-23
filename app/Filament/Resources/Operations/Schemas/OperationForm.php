<?php

namespace App\Filament\Resources\Operations\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Operation;
use App\Models\User;
use App\Services\OperationContextVisibilityService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class OperationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Operação')
                ->description('Defina a emissão e os parâmetros principais da operação. A situação é alterada pelas ações de ciclo de vida.')
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 12])
                ->schema([
                    Select::make('emission_id')
                        ->label('Emissão')
                        ->extraAttributes(['class' => 'bsi-operation-emission-select'])
                        ->placeholder('Selecione a emissão...')
                        ->options(fn (): array => static::emissionOptions())
                        ->getSearchResultsUsing(fn (string $search): array => static::emissionOptions($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => static::emissionLabel($value))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->columnSpan(['default' => 12, 'md' => 7])
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state === $old) {
                                return;
                            }

                            $set('developments', static::developmentsForEmission($state));
                            $set('due_date', static::emissionMaturityDate($state));
                        })
                        ->validationMessages([
                            'required' => 'Selecione a emissão.',
                        ]),

                    // A situação saiu do formulário: ela deixou de ser um atributo
                    // qualquer e passou a ser ciclo de vida, com transições
                    // válidas, pré-condições e motivo. Quem a altera são as ações
                    // Ativar / Concluir / Cancelar / Reabrir.
                    DatePicker::make('due_date')
                        ->label('Vencimento')
                        ->placeholder('Automático via emissão')
                        ->disabled()
                        ->dehydrated()
                        ->columnSpan(['default' => 12, 'md' => 5])
                        ->helperText('Preenchido automaticamente a partir da data de vencimento da emissão.'),
                ]),

            Section::make('Empreendimentos e Fundo de Obra')
                ->description('Os empreendimentos vinculados à emissão são carregados automaticamente. Informe o fundo de obra de cada um.')
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('empty_emission_hint')
                        ->hiddenLabel()
                        ->content(new HtmlString('<div class="flex items-center gap-2 p-3 text-xs text-slate-400 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-200/60 dark:border-slate-800"><svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg><span>Selecione uma emissão acima para carregar automaticamente os empreendimentos vinculados e definir seus respectivos fundos de obra.</span></div>'))
                        ->visible(fn (Get $get): bool => blank($get('emission_id'))),

                    Repeater::make('developments')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->columns(['default' => 1, 'md' => 2])
                        ->defaultItems(0)
                        ->minItems(1)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->visible(fn (Get $get): bool => filled($get('emission_id')))
                        ->itemLabel(fn (array $state): ?string => static::constructionLabel($state['construction_id'] ?? null))
                        ->schema([
                            Select::make('construction_id')
                                ->label('Empreendimento')
                                ->options(fn (Get $get): array => static::constructionOptionsForEmission($get('../../emission_id')))
                                ->required()
                                ->disabled()
                                ->dehydrated()
                                ->validationMessages([
                                    'required' => 'Selecione o empreendimento.',
                                ]),

                            static::moneyField('construction_fund_amount', 'Fundo de Obra'),
                        ])
                        ->afterStateHydrated(function (Repeater $component, ?Operation $record): void {
                            if (! $record instanceof Operation) {
                                return;
                            }

                            $component->state(
                                $record->planSets()
                                    ->whereNotNull('construction_id')
                                    ->get()
                                    ->map(fn ($plan): array => [
                                        'construction_id' => $plan->construction_id,
                                        'construction_fund_amount' => $plan->construction_fund_amount,
                                    ])
                                    ->all(),
                            );
                        }),
                ]),

            Section::make('Responsáveis pelo Fluxo de Medição')
                ->description('Defina os responsáveis por cada etapa da esteira de medição, a coordenação geral e os usuários notificados.')
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('workflow_guide')
                        ->hiddenLabel()
                        ->content(new HtmlString('<div class="flex items-center gap-1.5 py-1 px-2.5 text-xs text-slate-400 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/30 rounded border border-slate-200/50 dark:border-slate-800/80 mb-2"><span class="font-semibold text-slate-300 dark:text-slate-200">Sequência da Esteira:</span> <span class="text-amber-500 font-medium">1</span> Engenharia &nbsp;→&nbsp; <span class="text-amber-500 font-medium">2</span> Gestão &nbsp;→&nbsp; <span class="text-amber-500 font-medium">3</span> Compliance &nbsp;→&nbsp; <span class="text-amber-500 font-medium">4</span> Pagamentos &nbsp;→&nbsp; <span class="text-amber-500 font-medium">5</span> Finalização</div>'))
                        ->columnSpanFull(),

                    Grid::make(['default' => 1, 'md' => 2, 'lg' => 3])
                        ->schema([
                            static::userField('responsible_user_id', '1 · Engenharia', 'responsibleUser', 'Vistoria e laudo técnico da medição.'),
                            static::userField('stage2_reviewer_user_id', '2 · Gestão', 'stage2Reviewer', 'Análise gerencial e custos da obra.'),
                            static::userField('stage3_reviewer_user_id', '3 · Compliance', 'stage3Reviewer', 'Checagem de conformidade e certidões.'),
                            static::userField('payment_manager_user_id', '4 · Pagamentos', 'paymentManager', 'Registro e aprovação formal dos pagamentos.'),
                            static::userField('payment_receipt_uploader_user_id', 'Comprovantes', 'paymentReceiptUploader', 'Envio e remoção dos comprovantes financeiros.'),
                            static::userField('payment_finalizer_user_id', '5 · Finalização', 'paymentFinalizer', 'Encerramento e liberação final do ciclo.'),
                        ])
                        ->columnSpanFull(),

                    Grid::make(['default' => 1, 'md' => 2])
                        ->schema([
                            static::userField('assigned_user_id', 'Responsável Geral (Coordenação)', 'assignedUser', 'Visão macro e coordenação da operação.'),

                            Select::make('rejectionNotifyUsers')
                                ->label('Notificar em Caso de Recusa')
                                // Mesma regra dos responsáveis: quem já está na
                                // lista continua listado, quem entra agora
                                // precisa estar ativo e provisionado.
                                ->relationship(
                                    'rejectionNotifyUsers',
                                    'name',
                                    fn (Builder $query, ?Operation $record): Builder => $query->where(
                                        fn (Builder $available): Builder => $available
                                            ->operational()
                                            ->orWhereIn('users.id', $record?->rejectionNotifyUsers()->select('users.id') ?? []),
                                    ),
                                )
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->placeholder('Selecione os usuários a notificar...')
                                ->helperText('Usuários que receberão alerta automático em caso de recusa.'),
                        ])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Builds one repeater row per development of the emission, with the
     * development pre-filled and the construction fund left blank to edit.
     *
     * @return array<int, array{construction_id: int, construction_fund_amount: null}>
     */
    protected static function developmentsForEmission(mixed $emissionId): array
    {
        if (blank($emissionId)) {
            return [];
        }

        $user = auth()->user();

        if (! $user instanceof User
            || ! app(OperationContextVisibilityService::class)->findVisibleEmission($user, $emissionId) instanceof Emission) {
            return [];
        }

        return app(OperationContextVisibilityService::class)
            ->visibleConstructions($user, $emissionId)
            ->orderBy('development_name')
            ->pluck('id')
            ->map(fn (int $id): array => [
                'construction_id' => $id,
                'construction_fund_amount' => null,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function emissionOptions(?string $search = null): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $query = app(OperationContextVisibilityService::class)
            ->visibleEmissions($user)
            ->orderBy('name');

        if (filled($search)) {
            $query->where('name', 'like', '%'.trim((string) $search).'%');
        }

        return $query->limit(50)->pluck('name', 'id')->all();
    }

    protected static function emissionLabel(mixed $emissionId): ?string
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(OperationContextVisibilityService::class)->findVisibleEmission($user, $emissionId)?->name
            : null;
    }

    protected static function emissionMaturityDate(mixed $emissionId): ?string
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(OperationContextVisibilityService::class)->findVisibleEmission($user, $emissionId)?->maturity_date?->toDateString()
            : null;
    }

    /**
     * @return array<int, string>
     */
    protected static function constructionOptionsForEmission(mixed $emissionId, ?string $search = null): array
    {
        $user = auth()->user();

        if (! $user instanceof User || blank($emissionId)) {
            return [];
        }

        $query = app(OperationContextVisibilityService::class)
            ->visibleConstructions($user, $emissionId)
            ->orderBy('development_name');

        if (filled($search)) {
            $query->where('development_name', 'like', '%'.trim((string) $search).'%');
        }

        return $query->limit(50)->pluck('development_name', 'id')->all();
    }

    protected static function constructionLabel(mixed $constructionId): ?string
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(OperationContextVisibilityService::class)->findVisibleConstruction($user, $constructionId)?->development_name
            : null;
    }

    /**
     * Select de responsável que oferece apenas quem pode assumir a
     * responsabilidade agora -- ativo e provisionado --, sem apagar quem já a
     * detém.
     *
     * As duas coisas precisam conviver: um responsável que foi desligado depois
     * de atribuído continua sendo o responsável registrado, e o campo tem de
     * mostrá-lo, senão abrir a operação para editar outro campo pareceria ter
     * perdido a responsabilidade. Por isso o filtro é "elegível OU o valor já
     * gravado neste campo": o histórico continua renderizável, e a lista de
     * escolha nova não o oferece a mais ninguém.
     */
    protected static function userField(string $name, string $label, string $relationship, ?string $helperText = null): Select
    {
        $select = Select::make($name)
            ->label($label)
            ->placeholder('Selecione o responsável...')
            ->relationship(
                $relationship,
                'name',
                fn (Builder $query, ?Operation $record): Builder => $query->where(
                    fn (Builder $available): Builder => $available
                        ->operational()
                        ->orWhere('users.id', $record?->getAttribute($name)),
                ),
            )
            ->searchable()
            ->preload()
            ->disabled(fn (?Operation $record): bool => ! Gate::allows('manageResponsibilities', $record ?? Operation::class))
            ->dehydrated(fn (?Operation $record): bool => Gate::allows('manageResponsibilities', $record ?? Operation::class));

        if (filled($helperText)) {
            $select->helperText($helperText);
        }

        return $select;
    }

    protected static function moneyField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => blank($state) ? null : MoneyFormatter::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state))
            ->minValue(0)
            ->placeholder('0,00');
    }
}
