<?php

namespace App\Filament\Resources\SalesBoardCycles\Pages;

use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\DTOs\SalesBoards\SalesBoardBuilderReviewWorkspace as WorkspaceData;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementReview;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use App\Services\SalesBoards\SalesBoardBuilderReviewSubmissionService;
use App\Services\SalesBoards\SalesBoardBuilderReviewWorkspaceBuilder;
use App\Support\Money\IntegerMoney;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\RawJs;
use Livewire\Attributes\Url;

/**
 * A tela em que a competência é validada.
 *
 * É a mesma superfície que a construtora usará quando o acesso externo existir.
 * Hoje ela é aberta por um operador interno -- e o que for enviado daqui fica
 * registrado como tal, nunca como se a construtora tivesse enviado por conta
 * própria. A escolha do mecanismo de acesso externo continua em aberto, e nada
 * nesta página depende dela: o domínio recebe uma identidade abstrata, não um
 * usuário autenticado.
 *
 * Tudo o que aparece vem do snapshot congelado, através do workspace. A página
 * não consulta contrato, parcela, tabela nem permuta viva -- se a fonte mudou
 * desde a geração, a tela continua mostrando o quadro sobre o qual a construtora
 * está sendo perguntada, que é exatamente o ponto.
 */
class BuilderReviewWorkspace extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SalesBoardCycleResource::class;

    protected string $view = 'filament.resources.sales-board-cycles.pages.builder-review-workspace';

    protected static ?string $title = 'Validação da construtora';

    protected static ?string $breadcrumb = 'Validação';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-builder-review-page',
    ];

    /**
     * Qual tentativa está sendo exibida. Sem parâmetro, a tela abre a validação
     * em andamento -- ou, na falta dela, a mais recente.
     */
    #[Url]
    public ?int $review = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(SalesBoardCycleResource::canView($this->record), 403);
    }

    public function getSubheading(): ?string
    {
        $review = $this->currentReview();

        if ($review === null) {
            return 'Nenhuma validação foi aberta para esta competência.';
        }

        return sprintf(
            '%s · %s · %s',
            $review->attemptLabel(),
            $review->baseline?->versionLabel() ?? '—',
            $review->status->label(),
        );
    }

    public function currentReview(): ?SalesBoardBuilderReview
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        $query = SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->with(['baseline.lines', 'baseline.movements', 'sections', 'divergences']);

        if ($this->review !== null) {
            return $query->whereKey($this->review)->first();
        }

        return $query->orderByRaw("CASE WHEN status = 'em_andamento' THEN 0 ELSE 1 END")
            ->orderByDesc('attempt')
            ->first();
    }

    public function workspace(): ?WorkspaceData
    {
        $review = $this->currentReview();

        return $review === null
            ? null
            : app(SalesBoardBuilderReviewWorkspaceBuilder::class)->build($review);
    }

    /**
     * @return list<SalesBoardBuilderReview>
     */
    public function attempts(): array
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        return SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->orderByDesc('attempt')
            ->get()
            ->all();
    }

    /**
     * O motivo pelo qual a Gestão devolveu a rodada anterior.
     *
     * Só aparece enquanto a rodada exibida é a que nasceu da devolução: uma vez
     * enviada, a construtora já respondeu ao pedido, e continuar mostrando-o
     * faria a tela parecer que ainda há algo pendente. Nenhuma divergência
     * antiga é copiada -- o que atravessa é a pergunta, não a resposta.
     */
    public function returnReason(): ?string
    {
        $review = $this->currentReview();

        if (($review === null) || ! $review->isEditable() || ((int) $review->attempt <= 1)) {
            return null;
        }

        return SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $review->sales_board_cycle_id)
            ->where('status', SalesBoardManagementReviewStatus::Returned)
            ->orderByDesc('attempt')
            ->value('return_reason');
    }

    public function canEdit(): bool
    {
        return SalesBoardCycleResource::canRecalculate()
            && ($this->currentReview()?->isEditable() ?? false);
    }

    public function confirmSectionAction(): Action
    {
        return Action::make('confirmSection')
            ->label('Confirmar seção')
            ->icon('heroicon-o-check')
            ->color('success')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Confirmar esta seção')
            ->modalDescription('Você está declarando que as informações desta seção conferem com os registros da construtora.')
            ->modalSubmitActionLabel('Confirmar')
            ->schema([
                Textarea::make('comment')
                    ->label('Observação (opcional)')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->run(function () use ($arguments, $data): void {
                    app(SalesBoardBuilderReviewEditor::class)->confirmSection(
                        $this->section((int) $arguments['section']),
                        $data['comment'] ?? null,
                    );

                    Notification::make()->title('Seção confirmada')->success()->send();
                });
            });
    }

    public function reopenSectionAction(): Action
    {
        return Action::make('reopenSection')
            ->label('Desfazer confirmação')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->size('sm')
            ->action(function (array $arguments): void {
                $this->run(function () use ($arguments): void {
                    app(SalesBoardBuilderReviewEditor::class)->reopenSection($this->section((int) $arguments['section']));

                    Notification::make()->title('Confirmação desfeita')->success()->send();
                });
            });
    }

    public function declareDivergenceAction(): Action
    {
        return Action::make('declareDivergence')
            ->label('Apontar divergência')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->size('sm')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Apontar divergência')
            ->modalDescription('A posição apresentada não é alterada. A divergência é registrada como declaração da construtora e será analisada pela Gestão.')
            ->modalSubmitActionLabel('Registrar divergência')
            ->schema(fn (array $arguments): array => $this->divergenceForm((int) $arguments['section']))
            ->action(function (array $arguments, array $data): void {
                $this->run(function () use ($arguments, $data): void {
                    app(SalesBoardBuilderReviewEditor::class)->addDivergence(
                        $this->section((int) $arguments['section']),
                        SalesBoardBuilderDivergenceInput::fromArray($data),
                    );

                    Notification::make()->title('Divergência registrada')->success()->send();
                });
            });
    }

    public function removeDivergenceAction(): Action
    {
        return Action::make('removeDivergence')
            ->label('Remover')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Remover esta divergência')
            ->modalDescription('A seção voltará a "pendente de validação" e precisará ser respondida novamente.')
            ->action(function (array $arguments): void {
                $this->run(function () use ($arguments): void {
                    $divergence = SalesBoardBuilderDivergence::query()->findOrFail($arguments['divergence']);

                    abort_unless(
                        (int) $divergence->sales_board_builder_review_id === (int) $this->currentReview()?->getKey(),
                        403,
                    );

                    app(SalesBoardBuilderReviewEditor::class)->removeDivergence($divergence);

                    Notification::make()->title('Divergência removida')->success()->send();
                });
            });
    }

    public function submitReviewAction(): Action
    {
        return Action::make('submitReview')
            ->label('Enviar validação')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Enviar a validação para análise')
            ->modalDescription('Depois de enviada, a validação não pode mais ser alterada. Correções posteriores exigem uma nova rodada.')
            ->modalSubmitActionLabel('Enviar validação')
            ->schema([
                Textarea::make('overall_comment')
                    ->label('Observações gerais (opcional)')
                    ->rows(3)
                    ->maxLength(2000),

                Checkbox::make('declaration')
                    ->label('Confirmo que revisei as informações apresentadas e que as divergências registradas representam os pontos identificados pela construtora.')
                    ->accepted()
                    ->required()
                    ->validationMessages(['accepted' => 'É necessário confirmar a declaração para enviar.']),
            ])
            ->action(function (array $data): void {
                $this->run(function () use ($data): void {
                    $review = $this->currentReview();

                    app(SalesBoardBuilderReviewSubmissionService::class)->submit(
                        $review,
                        BuilderReviewerIdentity::forInternalUser(auth()->user()),
                        $data['overall_comment'] ?? null,
                    );

                    Notification::make()
                        ->title('Validação enviada')
                        ->body('A competência seguiu para análise da Gestão.')
                        ->success()
                        ->send();
                });
            });
    }

    /**
     * Os campos que cada tipo de divergência exige, montados a partir das regras
     * do próprio tipo -- e não de uma lista repetida aqui.
     *
     * @return list<Component>
     */
    protected function divergenceForm(int $sectionId): array
    {
        $section = $this->section($sectionId);
        $workspace = $this->workspace()?->section($section->section);

        $types = array_values(array_filter(
            SalesBoardBuilderDivergenceType::cases(),
            fn (SalesBoardBuilderDivergenceType $type): bool => in_array($section->section, $type->sections(), true),
        ));

        $anchors = collect($workspace?->rows ?? [])
            ->mapWithKeys(fn ($row): array => $section->section->isPosition()
                ? [$row->lineId => $row->displayName().($row->contractCode === null ? '' : ' · '.$row->contractCode)]
                : [$row->movementId => $row->displayName().' · '.($row->contractCode ?? '—')])
            ->all();

        $needs = fn (string $field): callable => fn (Get $get): bool => ($type = SalesBoardBuilderDivergenceType::tryFrom((string) $get('type'))) !== null
            && (in_array($field, $type->requiredDeclarations(), true) || in_array($field, $type->requiredAnyDeclarations(), true));

        return [
            Select::make('type')
                ->label('Tipo de divergência')
                ->options(collect($types)->mapWithKeys(fn (SalesBoardBuilderDivergenceType $type): array => [
                    $type->value => $type->label(),
                ])->all())
                ->default($types[0]->value ?? null)
                ->live()
                ->required(),

            Select::make($section->section->isPosition() ? 'sales_board_cycle_line_id' : 'sales_board_cycle_movement_id')
                ->label($section->section->isPosition() ? 'Unidade' : 'Lançamento')
                ->options($anchors)
                ->searchable()
                ->visible(fn (Get $get): bool => ($type = SalesBoardBuilderDivergenceType::tryFrom((string) $get('type'))) !== null
                    && ($type->requiresLine() || $type->requiresAnyAnchor() || $type->requiredMovementType() !== null)),

            TextInput::make('declared_block')
                ->label('Bloco')
                ->maxLength(50)
                ->visible($needs('declared_unit')),

            TextInput::make('declared_unit')
                ->label('Unidade')
                ->maxLength(50)
                ->required($needs('declared_unit'))
                ->visible($needs('declared_unit')),

            TextInput::make('declared_contract_code')
                ->label('Contrato informado pela construtora')
                ->maxLength(100)
                ->required($needs('declared_contract_code'))
                ->visible(fn (Get $get): bool => in_array(
                    SalesBoardBuilderDivergenceType::tryFrom((string) $get('type')),
                    [SalesBoardBuilderDivergenceType::SaleMissing, SalesBoardBuilderDivergenceType::SaleContractMismatch],
                    true,
                )),

            TextInput::make('declared_value')
                ->label('Valor informado pela construtora')
                ->prefix('R$')
                ->mask(RawJs::make('$money($input, \',\', \'.\')'))
                ->required($needs('declared_value'))
                ->visible($needs('declared_value')),

            DatePicker::make('declared_date')
                ->label('Data informada pela construtora')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->required($needs('declared_date'))
                ->visible($needs('declared_date')),

            Select::make('declared_classification')
                ->label('Situação correta segundo a construtora')
                ->options(collect(SalesBoardUnitClassification::resolvedCases())
                    ->mapWithKeys(fn (SalesBoardUnitClassification $case): array => [$case->value => $case->label()])
                    ->all())
                ->required($needs('declared_classification'))
                ->visible($needs('declared_classification')),

            Textarea::make('reason')
                ->label('Motivo')
                ->helperText('Descreva o que a construtora identificou. Este texto é o que a Gestão vai analisar.')
                ->rows(3)
                ->required()
                ->maxLength(2000)
                ->columnSpanFull(),
        ];
    }

    protected function section(int $sectionId): SalesBoardBuilderReviewSection
    {
        $review = $this->currentReview();

        $section = SalesBoardBuilderReviewSection::query()->findOrFail($sectionId);

        abort_unless((int) $section->sales_board_builder_review_id === (int) $review?->getKey(), 403);

        return $section;
    }

    /**
     * Recusas do domínio viram mensagem, não erro.
     *
     * Todas elas são situações previsíveis -- a versão mudou, a seção tem
     * divergência, a validação já foi enviada -- e quem está na tela precisa
     * saber o que fazer, não ver um stack trace.
     */
    protected function run(callable $callback): void
    {
        try {
            $callback();
        } catch (SalesBoardBuilderReviewException $exception) {
            Notification::make()
                ->title('Não foi possível concluir')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '—' : 'R$ '.IntegerMoney::format($cents);
    }
}
