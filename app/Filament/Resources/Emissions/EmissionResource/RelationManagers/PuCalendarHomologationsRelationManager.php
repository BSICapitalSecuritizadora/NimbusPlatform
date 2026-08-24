<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Domain\PuCalculator\Enums\PuCalendarHomologationDecision;
use App\Domain\PuCalculator\Enums\PuCalendarHomologationStatus;
use App\Domain\PuCalculator\Exceptions\PuCalendarHomologationException;
use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Domain\PuCalculator\Services\PuCalendarHomologationGuard;
use App\Domain\PuCalculator\Services\PuCalendarHomologationService;
use App\Enums\AccessPermission;
use App\Models\PuCalendarHomologation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class PuCalendarHomologationsRelationManager extends RelationManager
{
    protected static string $relationship = 'puCalendarHomologations';

    protected static ?string $title = 'Homologação CDI por calendário';

    protected static ?string $modelLabel = 'Cenário de homologação';

    protected static ?string $pluralModelLabel = 'Homologações CDI por calendário';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::PuCurveView->value) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('candidateCalendar'))
            ->recordTitleAttribute('candidate_calendar_code')
            ->description('Compara o calendário ativo e um candidato em memória. Nenhum cenário desta tela altera PU oficial, pagamentos ou a configuração vigente.')
            ->columns([
                TextColumn::make('status')
                    ->label('Etapa')
                    ->badge()
                    ->formatStateUsing(fn (PuCalendarHomologationStatus $state): string => $state->label())
                    ->color(fn (PuCalendarHomologationStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('candidate_calendar_code')
                    ->label('Calendário candidato')
                    ->description(fn (PuCalendarHomologation $record): ?string => $record->candidateCalendar?->purpose)
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('period_start')
                    ->label('Período')
                    ->formatStateUsing(fn (PuCalendarHomologation $record): string => sprintf(
                        '%s a %s',
                        $record->period_start->format('d/m/Y'),
                        $record->period_end->format('d/m/Y'),
                    )),
                TextColumn::make('result_summary.first_divergence_date')
                    ->label('Primeira divergência')
                    ->date('d/m/Y')
                    ->placeholder('Sem divergência ou não executado'),
                TextColumn::make('comparison_checksum')
                    ->label('Execução')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Comparada' : 'Não executada')
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'info' : 'gray'),
                TextColumn::make('executed_at')
                    ->label('Executada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Etapa')
                    ->options(collect(PuCalendarHomologationStatus::cases())
                        ->mapWithKeys(fn (PuCalendarHomologationStatus $status): array => [$status->value => $status->label()])
                        ->all()),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                Action::make('create_candidate')
                    ->label('Novo cenário candidato')
                    ->icon('heroicon-o-beaker')
                    ->color('primary')
                    ->authorize(fn (): bool => $this->canExecute())
                    ->modalHeading('Criar cenário experimental')
                    ->modalDescription('O sistema congelará a configuração ativa e variará somente o calendário. Criar ou executar este cenário não publica curva nem recomenda migração.')
                    ->modalSubmitActionLabel('Criar rascunho')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->schema($this->candidateForm())
                    ->fillForm(fn (): array => $this->candidateDefaults())
                    ->action(fn (array $data) => $this->createCandidate($data)),
            ])
            ->actions([
                Action::make('view_comparison')
                    ->label('Ver comparação')
                    ->icon('heroicon-o-chart-bar-square')
                    ->color('gray')
                    ->visible(fn (PuCalendarHomologation $record): bool => $record->comparison_checksum !== null)
                    ->modalHeading(fn (PuCalendarHomologation $record): string => 'Legacy × '.$record->candidate_calendar_code)
                    ->modalDescription('Resultado experimental, sem efeito sobre a curva oficial.')
                    ->modalWidth(Width::Full)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (PuCalendarHomologation $record) => view('filament.emissions.pu-calendar-homologation-result', [
                        'homologation' => $record,
                    ])),
                ActionGroup::make([
                    Action::make('execute_comparison')
                        ->label('Executar comparação')
                        ->icon('heroicon-o-play')
                        ->color('primary')
                        ->authorize(fn (): bool => $this->canExecute())
                        ->visible(fn (PuCalendarHomologation $record): bool => $record->status === PuCalendarHomologationStatus::Draft)
                        ->requiresConfirmation()
                        ->modalDescription('A comparação roda em memória e salva somente o diff e sua evidência. Nenhum PU operacional será persistido.')
                        ->action(fn (PuCalendarHomologation $record) => $this->executeComparison($record)),
                    Action::make('submit_review')
                        ->label('Enviar para revisão')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('warning')
                        ->authorize(fn (): bool => $this->canExecute())
                        ->visible(fn (PuCalendarHomologation $record): bool => $record->status === PuCalendarHomologationStatus::Draft && $record->comparison_checksum !== null)
                        ->requiresConfirmation()
                        ->modalDescription('Somente evidências com confiança material, gabarito avaliado e anos confirmados podem seguir para o checker.')
                        ->action(fn (PuCalendarHomologation $record) => $this->submitForReview($record)),
                    Action::make('approve')
                        ->label('Aprovar recomendação')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->authorize(fn (): bool => $this->canReview())
                        ->visible(fn (PuCalendarHomologation $record): bool => $record->status === PuCalendarHomologationStatus::ReadyForReview)
                        ->modalHeading('Aprovar recomendação de migração futura')
                        ->modalDescription('Esta decisão não altera o parâmetro ativo. Uma eventual vigência deverá ser aplicada em fase posterior e por permissão separada.')
                        ->schema([
                            Textarea::make('notes')
                                ->label('Conclusão da revisão')
                                ->required()
                                ->minLength(20)
                                ->rows(5),
                        ])
                        ->action(fn (PuCalendarHomologation $record, array $data) => $this->approve($record, (string) $data['notes'])),
                    Action::make('close_without_recommendation')
                        ->label('Encerrar sem recomendação')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->authorize(fn (): bool => $this->canReview())
                        ->visible(fn (PuCalendarHomologation $record): bool => in_array($record->status, [
                            PuCalendarHomologationStatus::Draft,
                            PuCalendarHomologationStatus::ReadyForReview,
                        ], true))
                        ->schema([
                            Select::make('decision')
                                ->label('Conclusão')
                                ->options([
                                    PuCalendarHomologationDecision::Inconclusive->value => PuCalendarHomologationDecision::Inconclusive->label(),
                                    PuCalendarHomologationDecision::RejectCandidate->value => PuCalendarHomologationDecision::RejectCandidate->label(),
                                ])
                                ->required(),
                            Textarea::make('notes')
                                ->label('Justificativa')
                                ->required()
                                ->minLength(20)
                                ->rows(5),
                        ])
                        ->action(fn (PuCalendarHomologation $record, array $data) => $this->closeWithoutRecommendation(
                            $record,
                            PuCalendarHomologationDecision::from((string) $data['decision']),
                            (string) $data['notes'],
                        )),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->tooltip('Ações controladas'),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-beaker')
            ->emptyStateHeading('Nenhum cenário de homologação')
            ->emptyStateDescription('Crie um rascunho somente depois de localizar a definição contratual de Dia Útil e de separar essa definição da B3 como mera divulgadora da Taxa DI.');
    }

    /** @return array<int, mixed> */
    private function candidateForm(): array
    {
        return [
            Section::make('Cenários comparados')
                ->description('Legacy usa a configuração ativa. Candidate herda todos os parâmetros e troca exclusivamente o calendário.')
                ->icon('heroicon-o-arrows-right-left')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('candidate_calendar_code')
                            ->label('Calendário candidato')
                            ->helperText('A lista exclui calendários legados, HML e calendários sem uso financeiro permitido.')
                            ->options(fn (): array => app(BusinessCalendarCatalogService::class)->optionsForNewConfiguration())
                            ->searchable()
                            ->required(),
                        DatePicker::make('period_start')
                            ->label('Início da comparação')
                            ->required(),
                        DatePicker::make('period_end')
                            ->label('Fim da comparação')
                            ->afterOrEqual('period_start')
                            ->required(),
                    ]),
                ]),
            Section::make('Matriz de evidências materiais')
                ->description('Registre o que cada função do cálculo exige. Uma menção à B3 como fonte da DI não define, por si só, o calendário de dias úteis.')
                ->icon('heroicon-o-document-magnifying-glass')
                ->collapsed()
                ->schema([
                    Repeater::make('evidence_matrix')
                        ->label('Funções contratuais')
                        ->schema([
                            Hidden::make('function'),
                            TextInput::make('function_label')
                                ->label('Função')
                                ->disabled()
                                ->dehydrated(false),
                            Textarea::make('rule')
                                ->label('Regra contratual')
                                ->required()
                                ->rows(2),
                            TextInput::make('document')
                                ->label('Documento')
                                ->required(),
                            TextInput::make('reference')
                                ->label('Página / cláusula')
                                ->required(),
                            Textarea::make('interpretation')
                                ->label('Interpretação')
                                ->required()
                                ->rows(2),
                            Select::make('confidence')
                                ->label('Confiança')
                                ->options([
                                    'Alta' => 'Alta',
                                    'Média' => 'Média',
                                    'Baixa' => 'Baixa',
                                    'Indeterminada' => 'Indeterminada',
                                ])
                                ->required(),
                        ])
                        ->columns(2)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false),
                ]),
            Section::make('Evidência principal da escolha')
                ->description('Responde por que esta emissão está sendo comparada com o calendário candidato.')
                ->icon('heroicon-o-shield-check')
                ->collapsed()
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('primary_evidence.source_document')
                            ->label('Documento')
                            ->required(),
                        TextInput::make('primary_evidence.clause_reference')
                            ->label('Cláusula')
                            ->required(),
                        TextInput::make('primary_evidence.page_reference')
                            ->label('Página'),
                        Textarea::make('primary_evidence.excerpt')
                            ->label('Excerto contratual')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('primary_evidence.notes')
                            ->label('Observações da interpretação')
                            ->rows(3)
                            ->columnSpanFull(),
                        Checkbox::make('primary_evidence.confirmed')
                            ->label('Confirmo que conferi este trecho no documento indicado')
                            ->helperText('Isto confirma a evidência documental; não aprova a homologação nem altera a configuração financeira.')
                            ->accepted()
                            ->columnSpanFull(),
                    ]),
                ]),
            Section::make('Gabarito externo')
                ->description('A indisponibilidade pode ser registrada, mas deve permanecer visível na conclusão.')
                ->icon('heroicon-o-table-cells')
                ->collapsed()
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('external_reference.availability')
                            ->label('Disponibilidade')
                            ->options([
                                'available' => 'Disponível',
                                'unavailable' => 'Não localizado',
                            ])
                            ->required(),
                        TextInput::make('external_reference.name')
                            ->label('Planilha / gabarito'),
                        Textarea::make('external_reference.result')
                            ->label('Resultado da comparação externa')
                            ->rows(3),
                        Textarea::make('external_reference.notes')
                            ->label('Observações')
                            ->rows(3),
                    ]),
                ]),
        ];
    }

    /** @return array<string, mixed> */
    private function candidateDefaults(): array
    {
        $parameter = $this->getOwnerRecord()->puParameter;
        $labels = [
            'daily_application' => 'Aplicação diária',
            'dup' => 'DUP',
            'spread' => 'Spread',
            'index_lag' => 'Defasagem da Taxa DI',
            'index_lookup' => 'Lookup da Taxa DI',
            'business_day_basis' => 'Base de dias úteis',
            'payment_dates' => 'Datas de pagamento',
        ];

        return [
            'period_start' => $parameter?->curve_start_date?->toDateString(),
            'period_end' => $parameter?->curve_end_date?->toDateString(),
            'evidence_matrix' => collect(PuCalendarHomologationGuard::REQUIRED_EVIDENCE_FUNCTIONS)
                ->map(fn (string $function): array => [
                    'function' => $function,
                    'function_label' => $labels[$function],
                ])
                ->all(),
            'external_reference' => ['availability' => 'unavailable'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function createCandidate(array $data): void
    {
        $this->runAction(
            fn () => app(PuCalendarHomologationService::class)->createDraft(
                $this->getOwnerRecord(),
                $data,
                auth()->user(),
            ),
            'Cenário candidato criado como rascunho.',
        );
    }

    private function executeComparison(PuCalendarHomologation $record): void
    {
        $this->runAction(
            fn () => app(PuCalendarHomologationService::class)->execute($record, auth()->user()),
            'Comparação executada sem efeitos na curva oficial.',
        );
    }

    private function submitForReview(PuCalendarHomologation $record): void
    {
        $this->runAction(
            fn () => app(PuCalendarHomologationService::class)->submitForReview($record, auth()->user()),
            'Homologação enviada para revisão independente.',
        );
    }

    private function approve(PuCalendarHomologation $record, string $notes): void
    {
        $this->runAction(
            fn () => app(PuCalendarHomologationService::class)->approve($record, auth()->user(), $notes),
            'Recomendação aprovada. A configuração ativa permanece inalterada.',
        );
    }

    private function closeWithoutRecommendation(
        PuCalendarHomologation $record,
        PuCalendarHomologationDecision $decision,
        string $notes,
    ): void {
        $this->runAction(
            fn () => app(PuCalendarHomologationService::class)->closeWithoutRecommendation(
                $record,
                auth()->user(),
                $decision,
                $notes,
            ),
            'Homologação encerrada sem recomendação de migração.',
        );
    }

    private function runAction(callable $action, string $successMessage): void
    {
        try {
            $action();

            Notification::make()
                ->success()
                ->title($successMessage)
                ->send();
        } catch (PuCalendarHomologationException $exception) {
            Notification::make()
                ->danger()
                ->title('Ação bloqueada')
                ->body($exception->getMessage())
                ->persistent()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('Não foi possível concluir a ação')
                ->body('Nenhuma configuração financeira foi alterada. Consulte o log operacional.')
                ->persistent()
                ->send();
        }
    }

    private function canExecute(): bool
    {
        return auth()->user()?->can(AccessPermission::PuCalendarHomologationExecute->value) ?? false;
    }

    private function canReview(): bool
    {
        return auth()->user()?->can(AccessPermission::PuCalendarHomologationReview->value) ?? false;
    }
}
